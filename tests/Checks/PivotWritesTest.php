<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\assignedRoleScopes;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->role = Role::query()->create(['name' => 'admin']);

    foreach ([null, 5, 6] as $scope) {
        AssignedRole::query()->create([
            'role_id' => $this->role->getKey(),
            'entity_type' => $this->user->getMorphClass(),
            'entity_id' => $this->user->getKey(),
            'scope' => $scope,
        ]);
    }
});

it('detaches only the rows the active tenant owns', function (): void {
    $this->warden->tenant()->to(5);

    $this->user->roles()->detach($this->role);

    expect(assignedRoleScopes())->toBe([null, 6]);
});

it('detaches only global rows when no tenant is active', function (): void {
    $this->user->roles()->detach($this->role);

    expect(assignedRoleScopes())->toBe([5, 6]);
});

it('syncs within the active tenant without touching the other scopes', function (): void {
    $this->warden->tenant()->to(5);

    $this->user->roles()->sync([]);

    expect(assignedRoleScopes())->toBe([null, 6]);
});

it('toggles within the active tenant without touching the other scopes', function (): void {
    $this->warden->tenant()->to(5);

    $this->user->roles()->toggle($this->role);

    expect(assignedRoleScopes())->toBe([null, 6]);
});

it('updates the pivot of the active tenant only', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    foreach ([null, 5] as $scope) {
        Grant::query()->create([
            'permission_id' => $permission->getKey(),
            'entity_type' => $this->user->getMorphClass(),
            'entity_id' => $this->user->getKey(),
            'forbidden' => false,
            'scope' => $scope,
        ]);
    }

    $this->warden->tenant()->to(5);

    $this->user->permissions()->updateExistingPivot($permission->getKey(), ['forbidden' => true]);

    expect(Grant::query()->withoutGlobalScopes()->where('scope', 5)->value('forbidden'))->toEqual(1)
        ->and(Grant::query()->withoutGlobalScopes()->whereNull('scope')->value('forbidden'))->toEqual(0);
});

it('stamps the active tenant on a row attached through the relation', function (): void {
    $this->warden->tenant()->to(7);

    $this->user->roles()->attach(Role::query()->create(['name' => 'editor'])->getKey());

    expect(AssignedRole::query()->withoutGlobalScopes()->where('scope', 7)->count())->toBe(1);
});

it('still reads the global rows the active tenant inherits', function (): void {
    $this->warden->tenant()->to(5);

    expect($this->user->roles()->count())->toBe(2);
});

it('detaches through the inverse relation within the active tenant only', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    foreach ([null, 5] as $scope) {
        Grant::query()->create([
            'permission_id' => $permission->getKey(),
            'entity_type' => $this->role->getMorphClass(),
            'entity_id' => $this->role->getKey(),
            'forbidden' => false,
            'scope' => $scope,
        ]);
    }

    $this->warden->tenant()->to(5);

    $permission->roles()->detach($this->role);

    expect(Grant::query()->withoutGlobalScopes()->pluck('scope')->all())->toBe([null]);
});

it('writes a role grant globally when the configuration keeps role grants unscoped', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    foreach ([null, 5] as $scope) {
        Grant::query()->create([
            'permission_id' => $permission->getKey(),
            'entity_type' => $this->role->getMorphClass(),
            'entity_id' => $this->role->getKey(),
            'forbidden' => false,
            'scope' => $scope,
        ]);
    }

    $this->warden->tenant()->to(5);
    $this->warden->tenant()->dontScopeRoleGrants();

    $this->role->permissions()->detach($permission);

    expect(Grant::query()->withoutGlobalScopes()->pluck('scope')->all())->toEqual([5]);
});

it('adds a tenant row rather than adopting the global one it inherits', function (): void {
    AssignedRole::query()->withoutGlobalScopes()->whereNotNull('scope')->delete();

    $this->warden->tenant()->to(5);

    $this->user->roles()->sync([$this->role->getKey()]);

    expect(assignedRoleScopes())->toEqual([null, 5]);
});
