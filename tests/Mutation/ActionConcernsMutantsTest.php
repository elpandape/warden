<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Cache;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Iris']);
});

it('grants named permissions that follow a permission model in one call', function (): void {
    $permission = Permission::query()->create(['name' => 'first']);

    $this->warden->allow($this->user)->to([$permission, 'second']);

    expect(Grant::query()->count())->toBe(2)
        ->and(Permission::query()->where('name', 'second')->exists())->toBeTrue();
});

it('revokes named permissions that follow a permission model in one call', function (): void {
    $permission = Permission::query()->create(['name' => 'first']);
    $this->warden->allow($this->user)->to([$permission, 'second']);

    $this->warden->disallow($this->user)->to([$permission, 'second']);

    expect(Grant::query()->count())->toBe(0);
});

it('revokes a permission model without validating the entity argument', function (): void {
    $permission = Permission::query()->create(['name' => 'archive']);
    $this->warden->allow($this->user)->to($permission);

    $this->warden->disallow($this->user)->to($permission, new Account);

    expect(Grant::query()->count())->toBe(0);
});

it('keeps a plain grant apart from a class-wide permission of the same name', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);

    $this->warden->allow($this->user)->to('view');

    expect(Permission::query()->where('name', 'view')->count())->toBe(2)
        ->and(Permission::query()->where('name', 'view')->whereNull('entity_type')->exists())->toBeTrue();
});

it('keeps a plain grant apart from a stray row carrying an entity id', function (): void {
    Permission::query()->create(['name' => 'stray', 'entity_id' => 7]);

    $this->warden->allow($this->user)->to('stray');

    expect(Permission::query()->where('name', 'stray')->count())->toBe(2)
        ->and(Permission::query()->where('name', 'stray')->whereNull('entity_id')->exists())->toBeTrue();
});

it('keeps a blanket grant apart from a stray blanket row carrying an entity id', function (): void {
    Permission::query()->create(['name' => 'publish', 'entity_type' => '*', 'entity_id' => 3]);

    $this->warden->allow($this->user)->to('publish', '*');

    expect(Permission::query()->where('name', 'publish')->count())->toBe(2)
        ->and(
            Permission::query()->where('name', 'publish')->where('entity_type', '*')->whereNull('entity_id')->exists(),
        )->toBeTrue();
});

it('revokes from an existing role resolved by name', function (): void {
    $this->warden->allow('editor')->to('edit-posts');

    $this->warden->disallow('editor')->to('edit-posts');

    expect(Grant::query()->count())->toBe(0)
        ->and(Role::query()->where('name', 'editor')->exists())->toBeTrue();
});

it('bumps the cache version exactly once for a write outside a transaction', function (): void {
    config()->set('warden.cache.enabled', true);
    Cache::store('array')->put('warden:v:a', 40, 60);
    Cache::store('array')->put('warden:v:g', 70, 60);

    $this->warden->allow($this->user)->to('edit-site');

    expect(Cache::store('array')->get('warden:v:a'))->toBe(41)
        ->and(Cache::store('array')->get('warden:v:g'))->toBe(71);
});
