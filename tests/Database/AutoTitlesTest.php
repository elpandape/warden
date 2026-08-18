<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

it('generates a title for roles from their name', function (): void {
    expect(Role::query()->create(['name' => 'site-admin'])->title)->toBe('Site admin')
        ->and(Role::query()->create(['name' => 'content_editor'])->title)->toBe('Content editor');
});

it('keeps an explicit role title', function (): void {
    $role = Role::query()->create(['name' => 'admin', 'title' => 'The Boss']);

    expect($role->title)->toBe('The Boss');
});

it('generates permission titles for every shape', function (array $attributes, string $title): void {
    expect(Permission::query()->create($attributes)->title)->toBe($title);
})->with([
    'simple' => [['name' => 'ban-users'], 'Ban users'],
    'everything owned' => [['name' => '*', 'entity_type' => '*', 'only_owned' => true], 'Manage everything owned'],
    'everything' => [['name' => '*', 'entity_type' => '*'], 'All permissions'],
    'all simple' => [['name' => '*'], 'All simple permissions'],
    'action everything owned' => [['name' => 'edit', 'entity_type' => '*', 'only_owned' => true], 'Edit everything owned'],
    'action everything' => [['name' => 'edit', 'entity_type' => '*'], 'Edit everything'],
    'blanket manage' => [['name' => '*', 'entity_type' => 'App\\Models\\Post'], 'Manage posts'],
    'blanket action' => [['name' => 'edit', 'entity_type' => 'App\\Models\\Post'], 'Edit posts'],
    'morph alias entity' => [['name' => 'view', 'entity_type' => 'warden.role'], 'View roles'],
    'instance' => [['name' => 'edit', 'entity_type' => 'App\\Models\\Post', 'entity_id' => 7], 'Edit post #7'],
    'manage instance' => [['name' => '*', 'entity_type' => 'App\\Models\\Post', 'entity_id' => 7], 'Manage post #7'],
]);

it('keeps an explicit permission title', function (): void {
    $permission = Permission::query()->create(['name' => 'edit', 'title' => 'Edit stuff']);

    expect($permission->title)->toBe('Edit stuff');
});

it('skips title generation when disabled by config', function (): void {
    config()->set('warden.titles.autogenerate', false);

    expect(Role::query()->create(['name' => 'site-admin'])->title)->toBeNull()
        ->and(Permission::query()->create(['name' => 'edit'])->title)->toBeNull();
});
