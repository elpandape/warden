<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Database\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

it('works with a global table prefix', function (): void {
    $connection = config('database.default');

    config()->set("database.connections.{$connection}.prefix", 'bouncer_');
    app('db')->purge(is_string($connection) ? $connection : null);

    migrateBouncerTables();

    $user = User::query()->create(['name' => 'Joseph']);
    $user->roles()->attach(Role::query()->create(['name' => 'admin']));

    expect($user->isA('admin'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue();

    ElPandaPe\Bouncer\Tests\Database\dropBouncerTables();
});

it('works with custom table names on top of the config', function (): void {
    config()->set('bouncer.tables.roles', 'authz_roles');
    app()->forgetInstance(ElPandaPe\Bouncer\Context::class);

    // Children first: assigned_roles holds a foreign key into the custom roles table.
    ElPandaPe\Bouncer\Tests\Database\dropBouncerTables();
    Schema::dropIfExists('authz_roles');

    migrateBouncerTables();

    $role = Role::query()->create(['name' => 'admin']);

    expect($role->getTable())->toBe('authz_roles')
        ->and(app('db')->table('authz_roles')->count())->toBe(1);

    ElPandaPe\Bouncer\Tests\Database\dropBouncerTables();
    Schema::dropIfExists('authz_roles');
});
