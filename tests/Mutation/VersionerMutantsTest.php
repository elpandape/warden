<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
    config()->set('warden.cache.enabled', true);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Vera']);
});

it('embeds the global counter in the strict segment, so a global write orphans strict payloads', function (): void {
    config()->set('warden.scope.null_behavior', 'strict');
    Cache::store('array')->put('warden:v:g', 7, 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('strict.c1.n0.g7');
});

it('builds the tenant segment from both counters, separated so their digits cannot collide', function (): void {
    $this->warden->tenant()->to(5);
    Cache::store('array')->put('warden:v:g', 3, 60);
    Cache::store('array')->put('warden:v:t.5', 8, 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('t5.c1.n0.g3.v8');
});

it('invalidates a tenant payload through its own tenant counter, never the global one', function (): void {
    $this->warden->tenant()->to(2);
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->warden->allow($this->user)->to('unrelated');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('seeds a fresh counter above the values an evicted predecessor may have handed out', function (): void {
    Cache::store('array')->forget('warden:v:a');
    Cache::store('array')->forget('warden:v:g');

    app(CacheKeyVersioner::class)->bump(null);

    expect(Cache::store('array')->get('warden:v:a'))->toBeInt()->toBeGreaterThanOrEqual(2)
        ->and(Cache::store('array')->get('warden:v:g'))->toBeInt()->toBeGreaterThanOrEqual(2);
});

it('falls back to zero for a junk counter that cannot be reseeded', function (): void {
    Cache::store('array')->put('warden:v:a', 'junk', 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('all.c1.n0.a0');
});
