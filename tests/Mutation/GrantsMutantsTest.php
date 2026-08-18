<?php

declare(strict_types=1);

use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

// Kills 8b72195cab980c35: with forRoleGrant forced to true, a USER's grant
// under dontScopeRoleGrants would be written globally instead of tenant-scoped.
it('keeps user grants tenant-scoped under dontScopeRoleGrants', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow($this->user)->to('browse');

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBe(1);
});

// Kills 66af53e43358e2a6: a role passed as a MODEL (not a string) must still
// announce the global write scope in the cancellable pre-event.
it('announces the global write scope for a role model authority', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->tenant()->dontScopeRoleGrants()->to(42);

    $role = Role::query()->create(['name' => 'auditor']);

    $scopes = [];
    Event::listen(GrantingPermission::class, function (GrantingPermission $event) use (&$scopes): void {
        $scopes[] = $event->scope;
    });

    $this->warden->allow($role)->to('audit');

    expect($scopes)->toBe([null]);
});

// Kills 6268324d6a196aca, da299e9628a3a7d4, d619bb7667a749d3 and
// cf0268007185038b: constraining an everyone-grant leaves lastAuthority null,
// so dropping any null-safe operator in reconstrain crashes on null.
it('constrains everyone-grants without an authority model', function (): void {
    $mine = Account::query()->create(['name' => 'Mine'])->refresh();
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allowEveryone()->to('view', Account::class)->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $other))->toBeFalse()
        ->and(Grant::query()->count())->toBe(1);
});

// Kills c2ffec3b5c7d5fbb: without the explicit scope key the repointed grant
// row gets stamped with the active tenant instead of staying global.
it('keeps the repointed role grant global when constraining under a tenant', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow('admin')->to('view', Account::class)->where('name', 'Mine');

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();
});

// Kills 15376962c077d8a4: with && turned into ||, a freshly created base
// permission is deleted even while another authority's grant still needs it.
it('keeps the base permission alive when another authority still holds it', function (): void {
    $account = Account::query()->create(['name' => 'Plain'])->refresh();
    $other = User::query()->create(['name' => 'Ana']);

    $grant = $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->allow($other)->to('view', Account::class);

    $grant->where('name', 'Mine');

    expect(Permission::query()->where('name', 'view')->count())->toBe(2)
        ->and(Gate::forUser($other)->allows('view', $account))->toBeTrue();
});

// Kills 156e938a87c13f65: without the cache version bump, reconstraining a
// grant keeps serving the stale unconstrained payload from the cache.
it('invalidates cached checks when a grant is reconstrained', function (): void {
    config()->set('warden.cache.enabled', true);
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $grant = $this->warden->allow($this->user)->to('view', Account::class);

    expect(Gate::forUser($this->user)->allows('view', $other))->toBeTrue();

    $grant->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('view', $other))->toBeFalse();
});

// Kills 76d87133bc68c4b0: the constrained twin of an instance permission must
// keep pointing at that exact instance, never widen to the whole class.
it('carries the entity id onto the constrained twin', function (): void {
    $account = Account::query()->create(['name' => 'Acme'])->refresh();

    $this->warden->allow($this->user)->to('edit', $account)->where('name', 'Acme');

    $twin = Permission::query()->where('name', 'edit')->whereNotNull('options')->sole();

    expect($twin->getAttribute('entity_id'))->toBe($account->getKey());
});

// Kills c2bcdfd0afc7a936: the constrained twin of an ownership grant must
// stay ownership-scoped instead of falling back to a plain grant.
it('carries the only-owned flag onto the constrained twin', function (): void {
    $this->warden->allow($this->user)->toOwn(Account::class, 'update')->where('name', 'Mine');

    $twin = Permission::query()->where('name', 'update')->whereNotNull('options')->sole();

    expect($twin->getAttribute('only_owned'))->toBeTrue();
});

// Kills 03f0407d09ef08e0: without the explicit scope key the twin permission
// gets stamped with the CURRENT tenant instead of inheriting the base row's.
it('keeps the base catalog scope on the twin across a tenant switch', function (): void {
    $this->warden->tenant()->to(1);
    $grant = $this->warden->allow($this->user)->to('view', Account::class);

    $this->warden->tenant()->to(2);
    $grant->where('name', 'Mine');

    $twin = Permission::query()->withoutGlobalScopes()
        ->where('name', 'view')->whereNotNull('options')->sole();

    expect($twin->getAttribute('scope'))->toBe(1);
});

// Kills 3c707560f4eff615, 44513a34d9141704, dbed8258e7f4efd0 and
// 44fb5dd54149dd36: options comparison must be key-order-insensitive at every
// depth, so a stored twin with reordered keys is reused, never duplicated.
it('reuses a stored twin whose option keys are ordered differently', function (): void {
    $reverse = function (array $value) use (&$reverse): array {
        $mapped = array_map(
            fn (mixed $item): mixed => is_array($item) ? $reverse($item) : $item,
            $value,
        );

        return array_is_list($mapped) ? $mapped : array_reverse($mapped, preserve_keys: true);
    };

    $options = ConstraintSerializer::serialize(new Builder()->where('name', 'Mine')->group());

    $stored = Permission::query()->create([
        'name' => 'view',
        'entity_type' => (new Account)->getMorphClass(),
        'options' => $reverse($options),
    ]);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');

    expect(Permission::query()->where('name', 'view')->count())->toBe(1)
        ->and(Grant::query()->sole()->getAttribute('permission_id'))->toBe($stored->getKey());
});

// Kills 76b8b581b5853797: permission MODELS must contribute their names to
// the cancellable pre-event payload, not be silently dropped.
it('announces model permission names in the granting pre-event', function (): void {
    config()->set('warden.cancellable_events', true);
    $permission = Permission::query()->create(['name' => 'preexisting']);

    $names = null;
    Event::listen(GrantingPermission::class, function (GrantingPermission $event) use (&$names): void {
        $names = $event->permissions;
    });

    $this->warden->allow($this->user)->to([$permission]);

    expect($names)->toBe(['preexisting']);
});

// Kills 35810ce06138493b and 3de657b865ac8437: revokes must target the
// tenant scope for users and the global scope for roles — forcing either
// direction strands one of the two rows.
it('revokes user and role grants at their own write scopes', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow($this->user)->to('edit');
    $this->warden->allow('admin')->to('audit');

    $this->warden->disallow($this->user)->to('edit');
    $this->warden->disallow('admin')->to('audit');

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

// Kills cc6b0d9d138bd445 and 9e5be72dac846aca: a revoke that deletes zero
// rows (the permission exists, but for another authority) must stay silent.
it('stays silent when revoking a permission held only by someone else', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $this->warden->allow($other)->to('publish');

    Event::fake([PermissionRevoked::class]);

    $this->warden->disallow($this->user)->to('publish');

    Event::assertNotDispatched(PermissionRevoked::class);

    expect(Grant::query()->count())->toBe(1);
});

// Kills 2b7c0a8b3832ae19: a plain disallow must announce PermissionRevoked,
// never its unforbid counterpart.
it('announces revokes with the revoked event, not the unforbidden one', function (): void {
    $this->warden->allow($this->user)->to('publish');

    Event::fake([PermissionRevoked::class, PermissionUnforbidden::class]);

    $this->warden->disallow($this->user)->to('publish');

    Event::assertDispatched(PermissionRevoked::class);
    Event::assertNotDispatched(PermissionUnforbidden::class);
});
