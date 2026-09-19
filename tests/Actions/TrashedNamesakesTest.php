<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\PermissionsSynced;
use ElPandaPe\Warden\Events\RolesSynced;
use ElPandaPe\Warden\Exceptions\TrashedCatalogRow;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingPermission;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToPermissions;
use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ana']);
});

dataset('writes that name the role', [
    'assign()' => [fn (Warden $warden, User $user): mixed => $warden->assign('editor')->to($user)],
    'allow()' => [fn (Warden $warden, User $user): mixed => $warden->allow('editor')->to('publish')],
    'forbid()' => [fn (Warden $warden, User $user): mixed => $warden->forbid('editor')->to('publish')],
    'sync() of its holder' => [fn (Warden $warden, User $user): mixed => $warden->sync($user)->roles(['editor'])],
    'sync() of the role' => [fn (Warden $warden, User $user): mixed => $warden->sync('editor')->permissions(['publish'])],
]);

it('refuses to mint a role whose global namesake is in the trash', function (Closure $write): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    SoftDeletingRole::query()->create(['name' => 'editor'])->delete();

    expect(fn (): mixed => $write($this->warden, $this->user))
        ->toThrow(TrashedCatalogRow::class, 'Role [editor] is in the trash: restore it or force-delete it before writing it again.')
        ->and(SoftDeletingRole::withTrashed()->where('name', 'editor')->count())->toBe(1);
})->with('writes that name the role');

it('refuses to mint a role whose namesake in the tenant catalog is in the trash', function (Closure $write): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    $this->warden->tenant()->to(5);
    SoftDeletingRole::query()->create(['name' => 'editor'])->delete();

    expect(fn (): mixed => $write($this->warden, $this->user))
        ->toThrow(TrashedCatalogRow::class, 'Role [editor] is in the trash: restore it or force-delete it before writing it again.');
})->with('writes that name the role');

it('refuses to mint a role whose global namesake is in the trash under a tenant that keeps the catalog global', function (Closure $write): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    SoftDeletingRole::query()->create(['name' => 'editor'])->delete();
    $this->warden->tenant()->onlyRelations()->to(5);

    expect(fn (): mixed => $write($this->warden, $this->user))
        ->toThrow(TrashedCatalogRow::class, 'Role [editor] is in the trash: restore it or force-delete it before writing it again.')
        ->and(SoftDeletingRole::withTrashed()->count())->toBe(1);
})->with('writes that name the role');

it('mints the tenant its own role when only the global namesake is in the trash', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    SoftDeletingRole::query()->create(['name' => 'editor'])->delete();
    $this->warden->tenant()->to(5);

    $this->warden->assign('editor')->to($this->user);

    expect(SoftDeletingRole::query()->where('name', 'editor')->sole()->getAttribute('scope'))->toBe(5)
        ->and($this->user->isA('editor'))->toBeTrue();
});

it('reuses a live namesake even while another one waits in the trash', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    SoftDeletingRole::query()->create(['name' => 'editor'])->delete();
    $live = SoftDeletingRole::query()->create(['name' => 'editor']);

    $this->warden->assign('editor')->to($this->user);

    expect($this->user->roles()->sole()->is($live))->toBeTrue()
        ->and(SoftDeletingRole::withTrashed()->count())->toBe(2);
});

it('refuses to mint a permission whose namesake is in the trash', function (Closure $grant, Closure $named): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $account = Account::query()->create(['name' => 'Acme']);

    $grant($this->warden, User::query()->create(['name' => 'Grace']), $account);
    SoftDeletingPermission::query()->sole()->delete();

    expect(fn (): mixed => $grant($this->warden, $this->user, $account))
        ->toThrow(TrashedCatalogRow::class, $named($account).' is in the trash: restore it or force-delete it before writing it again.')
        ->and(Grant::query()->withoutGlobalScopes()->count())->toBe(1);
})->with([
    'a plain one' => [
        fn (Warden $warden, User $user, Account $account): mixed => $warden->allow($user)->to('publish'),
        fn (Account $account): string => 'Permission [publish]',
    ],
    'one on a class' => [
        fn (Warden $warden, User $user, Account $account): mixed => $warden->allow($user)->to('edit', Account::class),
        fn (Account $account): string => 'Permission [edit] on ['.$account->getMorphClass().']',
    ],
    'one on an instance' => [
        fn (Warden $warden, User $user, Account $account): mixed => $warden->allow($user)->to('edit', $account),
        fn (Account $account): string => 'Permission [edit] on ['.$account->getMorphClass().':'.$account->getKey().']',
    ],
    'an owned one' => [
        fn (Warden $warden, User $user, Account $account): mixed => $warden->allow($user)->toOwn(Account::class, 'edit'),
        fn (Account $account): string => 'Permission [edit] on owned ['.$account->getMorphClass().']',
    ],
]);

it('refuses to mint the twin of a condition whose row is in the trash', function (): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $this->warden->allow(User::query()->create(['name' => 'Grace']))->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    SoftDeletingPermission::query()->whereNotNull('options')->sole()->delete();

    expect(fn (): mixed => $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme'))
        ->toThrow(TrashedCatalogRow::class, 'Permission [view] on ['.(new Account)->getMorphClass().'] with conditions is in the trash: restore it or force-delete it before writing it again.')
        ->and(SoftDeletingPermission::withTrashed()->count())->toBe(2);
});

it('reads each catalog table once before minting rows the default models cannot trash', function (): void {
    DB::enableQueryLog();

    $this->warden->allow('editor')->to('publish');

    $reads = fn (string $table): int => collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => preg_match('/^select .* from [`"]'.$table.'[`"]/', (string) $entry['query']) === 1)
        ->count();

    expect($reads('roles'))->toBe(1)
        ->and($reads('permissions'))->toBe(1);
});

it('lets a trashed role take writes that only count once it is restored', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    $editor = SoftDeletingRole::query()->create(['name' => 'editor']);
    $editor->delete();

    $this->warden->allow($editor)->to('publish');
    $this->warden->assign($editor)->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();

    $editor->restore();

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('leaves a trashed role assigned when a sync declares the live set', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    $this->warden->assign(['editor', 'writer'])->to($this->user);
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();
    $editor->delete();

    Event::fake([RolesSynced::class]);

    $this->warden->sync($this->user)->roles(['writer']);

    Event::assertDispatched(RolesSynced::class, fn (RolesSynced $event): bool => $event->changes->detached->isEmpty()
        && $event->changes->attached->isEmpty()
        && $event->changes->kept->pluck('name')->all() === ['writer']);
    expect(AssignedRole::query()->withoutGlobalScopes()->where('role_id', $editor->getKey())->exists())->toBeTrue();

    $editor->restore();

    expect($this->user->fresh()?->isA('editor'))->toBeTrue();
});

it('leaves a trashed permission granted when a sync declares the live set', function (): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $this->warden->allow($this->user)->to(['view', 'publish']);
    $publish = SoftDeletingPermission::query()->where('name', 'publish')->sole();
    $publish->delete();

    Event::fake([PermissionsSynced::class]);

    $this->warden->sync($this->user)->permissions(['view']);

    Event::assertDispatched(PermissionsSynced::class, fn (PermissionsSynced $event): bool => $event->changes->detached->isEmpty()
        && $event->changes->attached->isEmpty()
        && $event->changes->kept->pluck('name')->all() === ['view']);
    expect(Grant::query()->withoutGlobalScopes()->where('permission_id', $publish->getKey())->exists())->toBeTrue();

    $publish->restore();

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('leaves no trashed base behind a narrowing chain, so a later plain grant goes through', function (): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $bob = User::query()->create(['name' => 'Bob']);
    $other = Account::query()->create(['name' => 'Other']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($bob)->to('view', Account::class);

    expect(SoftDeletingPermission::onlyTrashed()->exists())->toBeFalse()
        ->and(Gate::forUser($bob)->allows('view', $other))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $other))->toBeFalse();
});

it('leaves no trashed twin behind a second where(), so its condition can be granted again', function (): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $bob = User::query()->create(['name' => 'Bob']);
    $acme = Account::query()->create(['name' => 'Acme']);
    $other = Account::query()->create(['name' => 'Other']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme')->where('id', '>', 0);
    $this->warden->allow($bob)->to('view', Account::class)->where('name', 'Acme');

    expect(SoftDeletingPermission::onlyTrashed()->exists())->toBeFalse()
        ->and(Gate::forUser($bob)->allows('view', $acme))->toBeTrue()
        ->and(Gate::forUser($bob)->allows('view', $other))->toBeFalse();
});

it('does not report an already-held trashed role as attached by a sync', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    $this->warden->assign(['editor', 'reviewer'])->to($this->user);
    $editor = SoftDeletingRole::query()->where('name', 'editor')->sole();
    $editor->delete();

    Event::fake([RolesSynced::class]);

    $this->warden->sync($this->user)->roles([$editor, 'reviewer']);

    Event::assertDispatched(RolesSynced::class, fn (RolesSynced $event): bool => $event->changes->attached->isEmpty()
        && $event->changes->detached->isEmpty()
        && $event->changes->kept->pluck('name')->all() === ['reviewer']);
});

it('does not report an already-held trashed permission as attached by a sync', function (): void {
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $this->warden->allow($this->user)->to(['publish', 'review']);
    $publish = SoftDeletingPermission::query()->where('name', 'publish')->sole();
    $publish->delete();

    Event::fake([PermissionsSynced::class]);

    $this->warden->sync($this->user)->permissions([$publish, 'review']);

    Event::assertDispatched(PermissionsSynced::class, fn (PermissionsSynced $event): bool => $event->changes->attached->isEmpty()
        && $event->changes->detached->isEmpty()
        && $event->changes->kept->pluck('name')->all() === ['review']);
});
