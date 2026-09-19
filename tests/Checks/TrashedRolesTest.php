<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\RoleClosure;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\cachedPayloadKey;
use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\storeAuthorityKeysAsText;
use function ElPandaPe\Warden\Tests\grantTuple;

beforeEach(function (): void {
    migrateWardenTables();
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $this->warden = app(Warden::class);
    $this->ana = User::query()->create(['name' => 'Ana']);
});

it('stops granting what a trashed role granted', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    expect($this->ana->can('publish'))->toBeTrue();

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and(Gate::forUser($this->ana)->allows('publish'))->toBeFalse();
});

it('lifts what a trashed role forbade, so a grant beneath it takes effect', function (): void {
    $this->warden->allow($this->ana)->to('delete');
    $this->warden->forbid('banned')->to('delete');
    $this->warden->assign('banned')->to($this->ana);

    expect($this->ana->can('delete'))->toBeFalse();

    SoftDeletingRole::query()->where('name', 'banned')->sole()->delete();

    expect($this->ana->can('delete'))->toBeTrue()
        ->and(Gate::forUser($this->ana)->allows('delete'))->toBeTrue();
});

it('leaves a trashed role out of whereCan, on the grant and the forbid side', function (): void {
    Account::query()->create(['name' => 'Acme']);
    $this->warden->allow('viewer')->to('view', Account::class);
    $this->warden->allow($this->ana)->to('edit', Account::class);
    $this->warden->forbid('frozen')->to('edit', Account::class);
    $this->warden->assign(['viewer', 'frozen'])->to($this->ana);

    expect(Account::query()->whereCan($this->ana, 'view')->count())->toBe(1)
        ->and(Account::query()->whereCan($this->ana, 'edit')->count())->toBe(0);

    SoftDeletingRole::query()->whereIn('name', ['viewer', 'frozen'])->get()
        ->each(fn (SoftDeletingRole $role): ?bool => $role->delete());

    expect(Account::query()->whereCan($this->ana, 'view')->count())->toBe(0)
        ->and(Account::query()->whereCan($this->ana, 'edit')->count())->toBe(1)
        ->and($this->ana)->toQueryExactlyWhatItCanCheck('view')
        ->and($this->ana)->toQueryExactlyWhatItCanCheck('edit');
});

it('agrees across isA, isAll, is and the whereIs scopes once a held role is trashed', function (bool $nested): void {
    config()->set('warden.roles.nested', $nested);
    $this->warden->assign('editor')->to($this->ana);

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect($this->ana->isA('editor'))->toBeFalse()
        ->and($this->ana->isAll('editor'))->toBeFalse()
        ->and($this->warden->is($this->ana)->an('editor'))->toBeFalse()
        ->and(User::query()->whereIs('editor')->count())->toBe(0)
        ->and(User::query()->whereIsAll('editor')->count())->toBe(0)
        ->and(User::query()->whereIsNot('editor')->pluck('name')->all())->toBe(['Ana']);
})->with(['nesting off' => false, 'nesting on' => true]);

it('reaches nothing through a trashed outer role', function (): void {
    config()->set('warden.roles.nested', true);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->assign('manager')->to($this->ana);

    expect($this->ana->can('publish'))->toBeTrue();

    $manager->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and($this->ana->isA('editor'))->toBeFalse()
        ->and($this->ana->isA('manager'))->toBeFalse()
        ->and(User::query()->whereIs('editor')->count())->toBe(0)
        ->and(User::query()->whereIs('manager')->count())->toBe(0);
});

it('climbs no trashed role in the middle of a chain', function (): void {
    config()->set('warden.roles.nested', true);
    $lead = SoftDeletingRole::query()->create(['name' => 'lead']);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->assign('manager')->to($lead);
    $this->warden->assign('lead')->to($this->ana);

    expect($this->ana->can('publish'))->toBeTrue()
        ->and(User::query()->whereIs('editor')->count())->toBe(1);

    $manager->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and($this->ana->isA('editor'))->toBeFalse()
        ->and($this->ana->isA('lead'))->toBeTrue()
        ->and(User::query()->whereIs('editor')->count())->toBe(0)
        ->and(User::query()->whereIs('lead')->count())->toBe(1);
});

it('reaches no trashed inner role, nor what it grants', function (): void {
    config()->set('warden.roles.nested', true);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->assign('manager')->to($this->ana);

    expect($this->ana->can('publish'))->toBeTrue();

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and($this->ana->isA('editor'))->toBeFalse()
        ->and($this->ana->isA('manager'))->toBeTrue()
        ->and(User::query()->whereIs('editor')->count())->toBe(0)
        ->and(User::query()->whereIs('manager')->count())->toBe(1);
});

it('still reaches a role through another live path', function (): void {
    config()->set('warden.roles.nested', true);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $lead = SoftDeletingRole::query()->create(['name' => 'lead']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to([$manager, $lead]);
    $this->warden->assign(['manager', 'lead'])->to($this->ana);

    $manager->delete();

    expect($this->ana->can('publish'))->toBeTrue()
        ->and($this->ana->isA('editor'))->toBeTrue()
        ->and(User::query()->whereIs('editor')->count())->toBe(1);
});

it('climbs no trashed role when authorities are keyed by text', function (): void {
    storeAuthorityKeysAsText();
    config()->set('warden.roles.nested', true);
    $lead = SoftDeletingRole::query()->create(['name' => 'lead']);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->assign('manager')->to($lead);
    $this->warden->assign('lead')->to($this->ana);
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();

    $manager->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and(array_map(strval(...), RoleClosure::reaching([$editor->getKey()])))
        ->toContain((string) $editor->getKey())
        ->not->toContain((string) $lead->getKey());
});

it('brings back what a trashed role granted, forbade and nested once it is restored', function (): void {
    config()->set('warden.roles.nested', true);
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->allow($this->ana)->to('delete');
    $this->warden->forbid('manager')->to('delete');
    $this->warden->assign('manager')->to($this->ana);

    $manager->delete();

    expect($this->ana->can('publish'))->toBeFalse()
        ->and($this->ana->can('delete'))->toBeTrue();

    $manager->restore();

    expect($this->ana->can('publish'))->toBeTrue()
        ->and($this->ana->can('delete'))->toBeFalse()
        ->and($this->ana->isA('editor'))->toBeTrue()
        ->and(User::query()->whereIs('editor')->count())->toBe(1);
});

it('explains a grant through a trashed role by the cause that remains', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect($this->warden->explain($this->ana, 'publish')->cause)->toBe(Cause::NoMatchingGrant);

    $this->warden->allowEveryone()->to('publish');

    expect($this->warden->explain($this->ana, 'publish')->cause)->toBe(Cause::GrantedToEveryone);
});

it('explains a prohibition a trashed role lifted by the grant beneath it', function (): void {
    $this->warden->allow($this->ana)->to('delete');
    $this->warden->forbid('banned')->to('delete');
    $this->warden->assign('banned')->to($this->ana);

    SoftDeletingRole::query()->where('name', 'banned')->sole()->delete();

    $why = $this->warden->explain($this->ana, 'delete');

    expect($why->allowed())->toBeTrue()
        ->and($why->cause)->toBe(Cause::GrantedDirectly);
});

it('invalidates the tenant a trashed role is held in, whichever tenant trashes or restores it', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->tenant()->onlyRelations();
    $this->warden->tenant()->onceTo(5, function (): void {
        $this->warden->allow('editor')->to('publish');
        $this->warden->assign('editor')->to($this->ana);
    });
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();
    $publishesInFive = fn (): bool => $this->warden->tenant()->onceTo(5, fn (): bool => Gate::forUser($this->ana)->allows('publish'));

    expect($publishesInFive())->toBeTrue();

    $this->warden->tenant()->onceTo(7, fn (): ?bool => $editor->delete());

    expect($publishesInFive())->toBeFalse();

    $this->warden->tenant()->onceTo(7, fn (): bool => $editor->restore());

    expect($publishesInFive())->toBeTrue();
});

it('invalidates a holder who reaches the trashed role through another', function (): void {
    config()->set('warden.cache.enabled', true);
    config()->set('warden.roles.nested', true);
    $this->warden->tenant()->onlyRelations();
    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->tenant()->onceTo(5, function () use ($manager): void {
        $this->warden->allow('editor')->to('publish');
        $this->warden->assign('editor')->to($manager);
    });
    $this->warden->assign('manager')->to($this->ana);

    expect($this->warden->tenant()->onceTo(5, fn (): bool => Gate::forUser($this->ana)->allows('publish')))->toBeTrue();

    $this->warden->tenant()->onceTo(7, fn (): ?bool => SoftDeletingRole::query()->where('name', 'editor')->sole()->delete());

    expect($this->warden->tenant()->onceTo(5, fn (): bool => Gate::forUser($this->ana)->allows('publish')))->toBeFalse();
});

it('lets a listener of the soft delete already see the role grant nothing', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);
    $seen = [];

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue();

    Event::listen(RoleDeleted::class, function () use (&$seen): void {
        $seen[] = Gate::forUser($this->ana)->allows('publish');
    });

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect($seen)->toBe([false]);
});

it('invalidates when a trashed role is restored, so its grant and its prohibition return', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow('editor')->to('publish');
    $this->warden->allow($this->ana)->to('delete');
    $this->warden->forbid('editor')->to('delete');
    $this->warden->assign('editor')->to($this->ana);
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();

    $editor->delete();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeFalse()
        ->and(Gate::forUser($this->ana)->allows('delete'))->toBeTrue();

    $editor->restore();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue()
        ->and(Gate::forUser($this->ana)->allows('delete'))->toBeFalse();
});

it('invalidates when a role goes to the trash through save()', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue();

    SoftDeletingRole::query()->where('name', 'editor')->sole()->forceFill(['deleted_at' => now()])->save();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeFalse();
});

it('invalidates on a soft delete and a restore with events disabled', function (): void {
    config()->set('warden.cache.enabled', true);
    config()->set('warden.events_enabled', false);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue();

    $editor->delete();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeFalse();

    $editor->restore();

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue();
});

it('invalidates a soft-deleted role even when a deleting listener halts the event', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->tenant()->onlyRelations();
    $this->warden->tenant()->onceTo(5, function (): void {
        $this->warden->allow('editor')->to('publish');
        $this->warden->assign('editor')->to($this->ana);
    });
    SoftDeletingRole::deleting(fn (): bool => true);
    $publishesInFive = fn (): bool => $this->warden->tenant()->onceTo(5, fn (): bool => Gate::forUser($this->ana)->allows('publish'));

    expect($publishesInFive())->toBeTrue();

    $this->warden->tenant()->onceTo(7, fn (): ?bool => SoftDeletingRole::query()->where('name', 'editor')->sole()->delete());

    expect($publishesInFive())->toBeFalse();
});

it('never reads a payload cached before trashed roles stopped counting', function (): void {
    config()->set('warden.cache.enabled', true);

    Cache::store('array')->put(
        str_replace(':p6:', ':p5:', cachedPayloadKey($this->ana)),
        ['v' => 5, 'grants' => [grantTuple(['name' => 'publish'])]],
        60,
    );

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeFalse();
});

it('reads every role check in as many statements whether or not the role model soft-deletes', function (): void {
    config()->set('warden.cache.enabled', false);
    config()->set('warden.roles.nested', true);
    Account::query()->create(['name' => 'Acme']);
    $this->warden->allow('editor')->to('view', Account::class);
    $this->warden->assign('editor')->to(SoftDeletingRole::query()->create(['name' => 'manager']));
    $this->warden->assign('manager')->to($this->ana);
    $statements = function (): Collection {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->ana->can('view', Account::class);
        $this->ana->isA('editor');
        User::query()->whereIs('editor')->count();
        Account::query()->whereCan($this->ana, 'view')->count();
        $this->warden->explain($this->ana, 'view', Account::class);

        return collect(DB::getQueryLog())->pluck('query');
    };

    $softDeleting = $statements();
    Context::resolve()->setModelClass('role', Role::class);
    $plain = $statements();

    $pivotReads = fn (Collection $queries): Collection => $queries
        ->filter(fn (string $query): bool => str_contains($query, 'assigned_roles') && ! str_contains($query, ' join '));

    expect($plain)->toHaveCount($softDeleting->count())
        ->and($plain->filter(fn (string $query): bool => str_contains($query, 'deleted_at'))->all())->toBeEmpty()
        ->and($pivotReads($plain)->filter(fn (string $query): bool => str_contains($query, '(select'))->all())->toBeEmpty()
        ->and($pivotReads($softDeleting)->reject(fn (string $query): bool => str_contains($query, 'deleted_at'))->all())->toBeEmpty()
        ->and($pivotReads($softDeleting))->toHaveCount($pivotReads($plain)->count())
        ->and($pivotReads($softDeleting))->not->toBeEmpty();
});

it('lists nothing a trashed role grants or forbids, and all of it again once restored', function (): void {
    $this->warden->allow($this->ana)->to('view');
    $this->warden->allow('editor')->to('publish');
    $this->warden->forbid('editor')->to('delete');
    $this->warden->assign('editor')->to($this->ana);

    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();
    $editor->delete();

    expect($this->ana->getPermissions()->pluck('name')->all())->toBe(['view'])
        ->and($this->ana->getForbiddenPermissions())->toBeEmpty();

    $editor->restore();

    expect($this->ana->getPermissions()->pluck('name')->sort()->values()->all())->toBe(['publish', 'view'])
        ->and($this->ana->getForbiddenPermissions()->pluck('name')->all())->toBe(['delete']);
});

it('lists nothing reached through a trashed role with nesting on', function (string $trashed, array $listed): void {
    config()->set('warden.roles.nested', true);

    $manager = SoftDeletingRole::query()->create(['name' => 'manager']);
    $this->warden->allow('manager')->to('view');
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($manager);
    $this->warden->assign('manager')->to($this->ana);

    SoftDeletingRole::query()->where('name', $trashed)->sole()->delete();

    expect($this->ana->getPermissions()->pluck('name')->all())->toBe($listed);
})->with([
    'the outer role' => ['manager', []],
    'the inner role' => ['editor', ['view']],
]);

it('lists in three statements with a soft-deleting role model, the trash filtered inside the assignment read', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $listed = $this->ana->getPermissions()->pluck('name')->all();

    $log = collect(DB::getQueryLog())->pluck('query');
    $assignments = $log->filter(fn (string $query): bool => str_contains($query, 'assigned_roles'));

    expect($listed)->toBe(['publish'])
        ->and($log)->toHaveCount(3)
        ->and($assignments)->toHaveCount(1)
        ->and($assignments->sole())->toContain('deleted_at');
});

it('invalidates the global scope a trashed role is held in beside tenant 0', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->tenant()->onlyRelations();

    $this->warden->tenant()->to(0);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->tenant()->remove();
    $this->warden->assign('editor')->to($luis);

    Cache::store('array')->put('warden:v:g', 40, 60);
    Cache::store('array')->put('warden:v:t.0', 70, 60);

    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    expect(Cache::store('array')->get('warden:v:g'))->toBe(41)
        ->and(Cache::store('array')->get('warden:v:t.0'))->toBe(71);
});
