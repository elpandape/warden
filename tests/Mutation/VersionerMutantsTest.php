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

// Kills 48e9bbd77b1839e1: without the global counter the strict segment is a
// constant, so global writes could never orphan strict-shape payloads.
it('embeds the global counter in the strict segment', function (): void {
    config()->set('warden.scope.null_behavior', 'strict');
    Cache::store('array')->put('warden:v:g', 7, 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('strict.c1.g7');
});

// Kills 0cda4537ab8dcced (tenant counter keyed by the filter kind 't.both'
// instead of the tenant identity), 49df6bc2ac90b5fb (missing '.v' separator
// lets global and tenant digits collide across counter values) and
// 73551b4a155f4018 (tenant counter dropped from the segment entirely).
it('builds the tenant segment from both counters with a separator', function (): void {
    $this->warden->tenant()->to(5);
    Cache::store('array')->put('warden:v:g', 3, 60);
    Cache::store('array')->put('warden:v:t.5', 8, 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('t5.c1.g3.v8');
});

// Kills 0cda4537ab8dcced and 73551b4a155f4018 behaviorally: a tenant write
// bumps only 'a' and its own tenant counter — never 'g' — so a stale tenant
// payload is orphaned only when that counter is part of the tenant segment.
it('invalidates a tenant payload through its own tenant counter', function (): void {
    $this->warden->tenant()->to(2);
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->warden->allow($this->user)->to('unrelated');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

// Kills a3b5603bbdbd22ea (seed call removed from increment),
// b4e2aa669898ba38 and 0a1b182ff2d6f553 (seeding guard inverted) and
// 0ec14175a5081a86 (add of the random seed removed): all of them make a
// fresh counter restart at 1 after the first bump, a value an evicted
// predecessor may already have handed out; the random seed keeps it >= 2.
it('seeds fresh counters at random before the first bump', function (): void {
    Cache::store('array')->forget('warden:v:a');
    Cache::store('array')->forget('warden:v:g');

    app(CacheKeyVersioner::class)->bump(null);

    expect(Cache::store('array')->get('warden:v:a'))->toBeInt()->toBeGreaterThanOrEqual(2)
        ->and(Cache::store('array')->get('warden:v:g'))->toBeInt()->toBeGreaterThanOrEqual(2);
});

// Kills d1bbe4d881a613c7 and 9c77522c2e54706f: add() refuses to overwrite a
// live junk entry, so the unseedable-counter fallback must read exactly 0.
it('falls back to zero for a junk counter that cannot be reseeded', function (): void {
    Cache::store('array')->put('warden:v:a', 'junk', 60);

    expect(app(CacheKeyVersioner::class)->segment())->toBe('all.c1.a0');
});
