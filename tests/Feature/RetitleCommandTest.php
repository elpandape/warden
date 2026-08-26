<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

it('converges a title warden wrote before 2.0', function (): void {
    $permission = Permission::query()->create([
        'name' => 'viewAny',
        'entity_type' => 'App\\Models\\Post',
        'title' => 'ViewAny posts',
    ]);
    $role = Role::query()->create(['name' => 'siteAdmin', 'title' => 'SiteAdmin']);

    $this->artisan('warden:retitle')
        ->expectsOutputToContain('Rewrote 1 permission title(s) and 1 role title(s).')
        ->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBe('View any posts')
        ->and($role->fresh()?->getAttribute('title'))->toBe('Site admin');
});

it('converges a title 2.0.0 mangled', function (): void {
    $permission = Permission::query()->create([
        'name' => 'page:App\\Filament\\Pages\\Settings',
        'title' => 'Page: app\\ filament\\ pages\\ settings',
    ]);

    $this->artisan('warden:retitle')->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBe('Page:App\\Filament\\Pages\\Settings');
});

it('leaves a title a person wrote alone', function (): void {
    $permission = Permission::query()->create(['name' => 'edit', 'title' => 'Edit stuff']);
    $role = Role::query()->create(['name' => 'admin', 'title' => 'The Boss']);

    $this->artisan('warden:retitle')->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBe('Edit stuff')
        ->and($role->fresh()?->getAttribute('title'))->toBe('The Boss');
});

it('leaves a title the current generator would produce untouched', function (): void {
    $permission = Permission::query()->create(['name' => 'ban-users']);

    $this->artisan('warden:retitle')
        ->expectsOutputToContain('Rewrote 0 permission title(s) and 0 role title(s).')
        ->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBe('Ban users');
});

it('leaves a null title null', function (): void {
    config()->set('warden.titles.autogenerate', false);

    $permission = Permission::query()->create(['name' => 'edit']);

    $this->artisan('warden:retitle')->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBeNull();
});

it('writes nothing under dry run', function (): void {
    $permission = Permission::query()->create(['name' => 'viewAny', 'title' => 'ViewAny']);

    $this->artisan('warden:retitle', ['--dry-run' => true])
        ->expectsOutputToContain('Would rewrite 1 permission title(s) and 0 role title(s).')
        ->assertExitCode(0);

    expect($permission->fresh()?->getAttribute('title'))->toBe('ViewAny');
});
