<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Relations\Relation;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

afterEach(function (): void {
    // The morph map is static state on Relation: keep it from leaking across tests.
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('registers stable morph aliases for the package models', function (): void {
    expect((new Role)->getMorphClass())->toBe('warden.role')
        ->and((new Permission)->getMorphClass())->toBe('warden.permission')
        ->and(Relation::getMorphedModel('warden.role'))->toBe(Role::class)
        ->and(Relation::getMorphedModel('warden.permission'))->toBe(Permission::class);
});

it('stores role grants under the morph alias', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $role->permissions()->attach(Permission::query()->create(['name' => 'edit']), ['forbidden' => false]);

    expect(
        app('db')->table('grants')->where('entity_type', 'warden.role')->count(),
    )->toBe(1);
});

it('works with an enforced morph map', function (): void {
    Relation::enforceMorphMap(['user' => User::class]);

    $user = User::query()->create(['name' => 'Joseph']);
    $user->roles()->attach(Role::query()->create(['name' => 'admin']));

    expect($user->isA('admin'))->toBeTrue()
        ->and(app('db')->table('assigned_roles')->value('entity_type'))->toBe('user');
});
