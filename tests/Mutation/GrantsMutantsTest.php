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

it('keeps user grants tenant-scoped under dontScopeRoleGrants', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow($this->user)->to('browse');

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBe(1);
});

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

it('constrains everyone-grants without an authority model', function (): void {
    $mine = Account::query()->create(['name' => 'Mine'])->refresh();
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allowEveryone()->to('view', Account::class)->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $other))->toBeFalse()
        ->and(Grant::query()->count())->toBe(1);
});

it('keeps the repointed role grant global when constraining under a tenant', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow('admin')->to('view', Account::class)->where('name', 'Mine');

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();
});

it('keeps the base permission alive when another authority still holds it', function (): void {
    $account = Account::query()->create(['name' => 'Plain'])->refresh();
    $other = User::query()->create(['name' => 'Ana']);

    $grant = $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->allow($other)->to('view', Account::class);

    $grant->where('name', 'Mine');

    expect(Permission::query()->where('name', 'view')->count())->toBe(2)
        ->and(Gate::forUser($other)->allows('view', $account))->toBeTrue();
});

it('invalidates cached checks when a grant is reconstrained', function (): void {
    config()->set('warden.cache.enabled', true);
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $grant = $this->warden->allow($this->user)->to('view', Account::class);

    expect(Gate::forUser($this->user)->allows('view', $other))->toBeTrue();

    $grant->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('view', $other))->toBeFalse();
});

it('carries the entity id onto the constrained twin instead of widening to the class', function (): void {
    $account = Account::query()->create(['name' => 'Acme'])->refresh();

    $this->warden->allow($this->user)->to('edit', $account)->where('name', 'Acme');

    $twin = Permission::query()->where('name', 'edit')->whereNotNull('options')->sole();

    expect($twin->getAttribute('entity_id'))->toBe($account->getKey());
});

it('carries the only-owned flag onto the constrained twin instead of falling back to a plain grant', function (): void {
    $this->warden->allow($this->user)->toOwn(Account::class, 'update')->where('name', 'Mine');

    $twin = Permission::query()->where('name', 'update')->whereNotNull('options')->sole();

    expect($twin->getAttribute('only_owned'))->toBeTrue();
});

it('keeps the base catalog scope on the twin across a tenant switch', function (): void {
    $this->warden->tenant()->to(1);
    $grant = $this->warden->allow($this->user)->to('view', Account::class);

    $this->warden->tenant()->to(2);
    $grant->where('name', 'Mine');

    $twin = Permission::query()->withoutGlobalScopes()
        ->where('name', 'view')->whereNotNull('options')->sole();

    expect($twin->getAttribute('scope'))->toBe(1);
});

it('reuses a stored twin whose option keys are ordered differently at any depth', function (): void {
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

it('revokes user and role grants at their own write scopes', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->allow($this->user)->to('edit');
    $this->warden->allow('admin')->to('audit');

    $this->warden->disallow($this->user)->to('edit');
    $this->warden->disallow('admin')->to('audit');

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('stays silent when revoking a permission held only by someone else', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $this->warden->allow($other)->to('publish');

    Event::fake([PermissionRevoked::class]);

    $this->warden->disallow($this->user)->to('publish');

    Event::assertNotDispatched(PermissionRevoked::class);

    expect(Grant::query()->count())->toBe(1);
});

it('announces revokes with the revoked event, not the unforbidden one', function (): void {
    $this->warden->allow($this->user)->to('publish');

    Event::fake([PermissionRevoked::class, PermissionUnforbidden::class]);

    $this->warden->disallow($this->user)->to('publish');

    Event::assertDispatched(PermissionRevoked::class);
    Event::assertNotDispatched(PermissionUnforbidden::class);
});
