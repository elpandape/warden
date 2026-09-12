<?php

declare(strict_types=1);

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('relates authorities to roles through the assigned roles pivot', function (): void {
    $role = Role::query()->create(['name' => 'admin']);

    $this->user->roles()->attach($role);

    expect($this->user->roles()->pluck('name')->all())->toBe(['admin'])
        ->and($this->user->roles()->first()?->pivot)->toBeInstanceOf(AssignedRole::class);
});

it('relates authorities to permissions through the grants pivot', function (): void {
    $permission = Permission::query()->create(['name' => 'edit-site']);

    $this->user->permissions()->attach($permission, ['forbidden' => false]);

    $pivot = $this->user->permissions()->first()?->pivot;

    expect($this->user->permissions()->pluck('name')->all())->toBe(['edit-site'])
        ->and($pivot)->toBeInstanceOf(Grant::class)
        ->and($pivot?->getAttribute('forbidden'))->toBeFalse();
});

it('relates roles to permissions through the same grants pivot', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'edit-site']);

    $role->permissions()->attach($permission, ['forbidden' => false]);

    expect($role->permissions()->pluck('name')->all())->toBe(['edit-site'])
        ->and($permission->roles()->pluck('name')->all())->toBe(['editor']);
});

it('resolves pivot tables from the context when used standalone', function (): void {
    expect((new AssignedRole)->getTable())->toBe('assigned_roles')
        ->and((new Grant)->getTable())->toBe('grants');
});

it('does not use pivot timestamps by default', function (): void {
    expect((new AssignedRole)->usesTimestamps())->toBeFalse()
        ->and((new Grant)->usesTimestamps())->toBeFalse();

    config()->set('warden.pivot_timestamps', true);

    expect((new AssignedRole)->usesTimestamps())->toBeTrue()
        ->and((new Grant)->usesTimestamps())->toBeTrue();
});

it('declares no cast on entity ids so uuid and ulid keys survive', function (): void {
    // A hardcoded int cast here would truncate uuid and ulid keys.
    expect((new Permission)->getCasts())->not->toHaveKey('entity_id');
});

it('selects pivot timestamps when the opt-in is enabled', function (): void {
    $user = User::query()->create(['name' => 'Joseph']);

    expect($user->roles()->getPivotColumns())->not->toContain('created_at');

    config()->set('warden.pivot_timestamps', true);

    expect($user->roles()->getPivotColumns())->toContain('created_at', 'updated_at')
        ->and($user->permissions()->getPivotColumns())->toContain('created_at');
});

it('reaches the permission and the holder from a grant row', function (): void {
    app(Warden::class)->allow($this->user)->to('edit-site');

    $grant = Grant::query()->sole();

    expect($grant->permission?->getAttribute('name'))->toBe('edit-site')
        ->and($grant->entity?->is($this->user))->toBeTrue();
});

it('reaches the role and the holder from an assignment row', function (): void {
    app(Warden::class)->assign('editor')->to($this->user);

    $assignment = AssignedRole::query()->sole();

    expect($assignment->role?->getAttribute('name'))->toBe('editor')
        ->and($assignment->entity?->is($this->user))->toBeTrue()
        ->and($assignment->restrictedTo)->toBeNull();
});

it('reaches the context a restricted assignment is pinned to', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    app(Warden::class)->assign('editor')->on($org)->to($this->user);

    expect(AssignedRole::query()->sole()->restrictedTo?->is($org))->toBeTrue();
});

it('refuses a second catalog row with the same identity', function (): void {
    Permission::query()->create(['name' => 'view', 'entity_type' => Account::class]);

    expect(fn (): mixed => Permission::query()->create(['name' => 'view', 'entity_type' => Account::class]))
        ->toThrow(QueryException::class);
});

it('still allows rows that differ only in their conditions', function (): void {
    Permission::query()->create(['name' => 'view', 'entity_type' => Account::class]);
    Permission::query()->create(['name' => 'view', 'entity_type' => Account::class, 'options' => ['v' => 1, 'g' => ['i' => [['and', []]]]]]);

    expect(Permission::query()->where('name', 'view')->count())->toBe(2);
});

it('prints a row created in this request from the defaults it left unset, even after an identity edit', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    expect($permission->getAttribute('identity_key'))->toBe(PermissionIdentity::from(null, null, false, null, null));

    $permission->setAttribute('entity_type', Account::class);
    $permission->save();

    expect(Permission::query()->sole()->getAttribute('identity_key'))
        ->toBe(PermissionIdentity::from(Account::class, null, false, null, null));
});

it('recomputes a stale identity key when the whole row is saved', function (): void {
    $expected = Permission::query()->create(['name' => 'view', 'entity_type' => Account::class])->getAttribute('identity_key');

    DB::table('permissions')->update(['identity_key' => 'stale']);

    $reread = Permission::query()->sole();
    $reread->setAttribute('title', 'Renamed');
    $reread->save();

    expect(Permission::query()->sole()->getAttribute('identity_key'))->toBe($expected);
});

it('refuses to change what identifies a partially loaded permission', function (): void {
    $stored = Permission::query()->create(['name' => 'edit', 'entity_type' => Account::class, 'only_owned' => true]);

    $partial = Permission::query()->select(['id', 'entity_type'])->sole();
    $partial->setAttribute('entity_type', User::class);

    expect(fn (): bool => $partial->save())
        ->toThrow(ConfigurationException::class, 'Load the whole permission row before changing what identifies it.');

    $row = Permission::query()->sole();

    expect($row->getAttribute('entity_type'))->toBe(Account::class)
        ->and($row->getAttribute('identity_key'))->toBe($stored->getAttribute('identity_key'));
});

it('saves a cosmetic edit of a partially loaded permission in strict mode', function (): void {
    Permission::query()->create(['name' => 'view', 'entity_type' => Account::class]);
    Model::preventAccessingMissingAttributes();

    try {
        $partial = Permission::query()->select(['id', 'title'])->sole();
        $partial->setAttribute('title', 'Renamed');
        $partial->save();
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }

    expect(Permission::query()->sole()->getAttribute('title'))->toBe('Renamed');
});
