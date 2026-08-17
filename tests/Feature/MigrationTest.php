<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

function bouncerMigration(): Migration
{
    return require __DIR__.'/../../database/migrations/create_bouncer_tables.php.stub';
}

it('creates and drops the four bouncer tables', function (): void {
    $migration = bouncerMigration();

    $migration->up();

    expect(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('assigned_roles'))->toBeTrue()
        ->and(Schema::hasTable('grants'))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('permissions'))->toBeFalse()
        ->and(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasTable('assigned_roles'))->toBeFalse()
        ->and(Schema::hasTable('grants'))->toBeFalse();
});

it('ships the schema v2 columns, including the ones reserved for v0.8', function (): void {
    $migration = bouncerMigration();
    $migration->up();

    expect(Schema::hasColumns('permissions', [
        'id', 'name', 'title', 'entity_type', 'entity_id', 'only_owned', 'options', 'scope',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('assigned_roles', [
            'role_id', 'entity_type', 'entity_id', 'restricted_to_type', 'restricted_to_id', 'scope',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('grants', [
            'permission_id', 'entity_type', 'entity_id', 'forbidden', 'scope',
        ]))->toBeTrue();

    $migration->down();
});

it('honors custom table names from the config', function (): void {
    config()->set('bouncer.tables', [
        'permissions' => 'custom_permissions',
        'roles' => 'custom_roles',
        'assigned_roles' => 'custom_assigned_roles',
        'grants' => 'custom_grants',
    ]);

    $migration = bouncerMigration();
    $migration->up();

    expect(Schema::hasTable('custom_permissions'))->toBeTrue()
        ->and(Schema::hasTable('custom_grants'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeFalse();

    $migration->down();
});
