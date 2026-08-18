<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

it('creates permissions through the factory', function (): void {
    $permission = Permission::factory()->create();

    expect($permission->exists)->toBeTrue()
        ->and($permission->name)->toStartWith('permission-');
});

it('creates roles through the factory with overrides', function (): void {
    $role = Role::factory()->create(['name' => 'admin']);

    expect($role->exists)->toBeTrue()
        ->and($role->name)->toBe('admin')
        ->and($role->title)->toBe('Admin');
});
