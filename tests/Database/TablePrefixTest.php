<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

it('works with a global table prefix', function (): void {
    $connection = config('database.default');

    config()->set("database.connections.{$connection}.prefix", 'warden_');
    app('db')->purge(is_string($connection) ? $connection : null);

    migrateWardenTables();

    $user = User::query()->create(['name' => 'Joseph']);
    $user->roles()->attach(Role::query()->create(['name' => 'admin']));

    expect($user->isA('admin'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue();

    ElPandaPe\Warden\Tests\Database\dropWardenTables();
});

it('works with custom table names on top of the config', function (): void {
    config()->set('warden.tables.roles', 'authz_roles');
    app()->forgetInstance(ElPandaPe\Warden\Context::class);

    // Children first: assigned_roles holds a foreign key into the custom roles table.
    ElPandaPe\Warden\Tests\Database\dropWardenTables();
    Schema::dropIfExists('authz_roles');

    migrateWardenTables();

    $role = Role::query()->create(['name' => 'admin']);

    expect($role->getTable())->toBe('authz_roles')
        ->and(app('db')->table('authz_roles')->count())->toBe(1);

    ElPandaPe\Warden\Tests\Database\dropWardenTables();
    Schema::dropIfExists('authz_roles');
});
