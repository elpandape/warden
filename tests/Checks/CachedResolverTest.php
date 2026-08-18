<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Checks\Resolvers\CachedResolver;
use ElPandaPe\Bouncer\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\PlainCacheStore;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

function cachedPayloadKey(User $authority): string
{
    return implode(':', [
        'bouncer',
        'p2',
        app(CacheKeyVersioner::class)->segment(),
        $authority->getMorphClass(),
        (string) $authority->getKey(),
    ]);
}

beforeEach(function (): void {
    migrateBouncerTables();
    config()->set('bouncer.cache.enabled', true);

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('answers every grant shape like the database engine', function (): void {
    $account = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $foreign = Account::query()->create(['name' => 'Other'])->refresh();

    $this->bouncer->allow($this->user)->to('ban-users');
    $this->bouncer->allow($this->user)->to('edit', Account::class);
    $this->bouncer->allow($this->user)->toOwn(Account::class, ['delete']);
    $this->bouncer->allow('admin')->to('audit');
    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->allowEveryone()->to('browse');
    $this->bouncer->forbid($this->user)->to('edit', $foreign);

    $gate = Gate::forUser($this->user);

    expect($gate->allows('ban-users'))->toBeTrue()
        ->and($gate->allows('edit', $account))->toBeTrue()
        ->and($gate->allows('delete', $account))->toBeTrue()
        ->and($gate->allows('delete', $foreign))->toBeFalse()
        ->and($gate->allows('audit'))->toBeTrue()
        ->and($gate->allows('browse'))->toBeTrue()
        ->and($gate->allows('edit', $foreign))->toBeFalse()
        ->and($gate->allows('missing'))->toBeFalse();
});

it('abstains on non-model entity strings without touching the cache', function (): void {
    expect(app(Resolver::class)->resolve($this->user, 'edit', 'not-a-class')->isAbstained())->toBeTrue();
});

it('serves checks from the cached payload without new queries', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    DB::enableQueryLog();

    // The issue #430 shape: N checks after the first one cost zero queries.
    foreach (range(1, 25) as $i) {
        Gate::forUser($this->user)->allows('edit-site');
        Gate::forUser($this->user)->allows('other-permission');
    }

    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('keeps serving the cached payload when rows change behind its back', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A raw delete never bumps the version: the payload stays, by design.
    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reflects every action immediately through version bumps', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $this->bouncer->disallow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->allow('admin')->to('audit');
    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();

    $this->bouncer->retract('admin')->from($this->user);
    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();

    $this->bouncer->sync($this->user)->permissions(['publish']);
    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();

    $this->bouncer->forbid($this->user)->to('publish');
    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();

    $this->bouncer->unforbid($this->user)->to('publish');
    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('keeps tenant payloads independent through per-tenant versions', function (): void {
    $this->bouncer->tenant()->to(2);
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A write in tenant 1 must not invalidate tenant 2's cached payload:
    // the raw delete is only visible once something bumps tenant 2.
    Grant::query()->withoutGlobalScopes()->where('scope', 2)->delete();
    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->allow($this->user)->to('other');
    });

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A global write bumps every shape, so the stale payload is rebuilt.
    $this->bouncer->tenant()->removeOnce(function (): void {
        $this->bouncer->allowEveryone()->to('browse');
    });

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('invalidates everything with refresh', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('invalidates one authority with refreshFor', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->bouncer->refreshFor($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('ignores refreshFor when the cache is disabled', function (): void {
    config()->set('bouncer.cache.enabled', false);
    $this->bouncer->allow($this->user)->to('edit-site');

    // Passthrough mode: the database engine answers and refreshFor is a no-op.
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and($this->bouncer->refreshFor($this->user))->toBe($this->bouncer);

    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('expires payloads after the configured ttl', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    app()->forgetScopedInstances();
    $this->travel(25)->hours();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->travelBack();
});

it('stores a versioned payload with the fields v0.8 will need', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    $payload = Cache::store('array')->get(cachedPayloadKey($this->user));

    expect($payload)->toBeArray()
        ->and($payload['v'])->toBe(2)
        ->and($payload['grants'][0])->toHaveKeys([
            'key', 'name', 'entity_type', 'entity_id', 'only_owned',
            'forbidden', 'options', 'restricted_to_type', 'restricted_to_id',
        ]);
});

it('discards cached payloads from other payload versions', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    // A payload written by a different package version must be rebuilt.
    Cache::store('array')->put(cachedPayloadKey($this->user), ['v' => 0, 'grants' => []], 60);
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('falls back to a direct rebuild when the stampede lock times out', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    $resolver = new CachedResolver(
        app(Resolver::class),
        ElPandaPe\Bouncer\Context::resolve(),
        app(CacheKeyVersioner::class),
        lockWaitSeconds: 0,
    );

    // Someone else holds the lock and never releases it: serve directly.
    Cache::store('array')->getStore()->lock(cachedPayloadKey($this->user).':lock', 60)->get();

    expect($resolver->resolve($this->user, 'edit-site')->isGranted())->toBeTrue();
});

it('rebuilds without locking on stores that cannot lock', function (): void {
    Cache::extend('plain', fn (): Illuminate\Contracts\Cache\Repository => Cache::repository(new PlainCacheStore));
    config()->set('cache.stores.plain', ['driver' => 'plain']);
    config()->set('bouncer.cache.store', 'plain');

    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // Cached: a raw delete stays invisible until a bump.
    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reseeds corrupted version counters at random', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    Cache::store('array')->put('bouncer:v:a', 'junk', 60);
    Cache::store('array')->put('bouncer:v:g', 'junk', 60);
    app()->forgetScopedInstances();

    // A corrupted counter reseeds instead of resurrecting stale entries.
    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('resets memoization between scoped container lifecycles', function (): void {
    $first = app(Resolver::class);

    app()->forgetScopedInstances();

    expect(app(Resolver::class))->not->toBe($first)
        ->and($first)->toBeInstanceOf(CachedResolver::class);
});

it('matches wildcard and class shapes from the cached payload', function (): void {
    $account = Account::query()->create(['name' => 'Plain'])->refresh();
    $other = User::query()->create(['name' => 'Ana']);

    $this->bouncer->allow($this->user)->to('edit', Account::class);

    $resolver = app(Resolver::class);

    expect(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse()
        ->and($resolver->resolve($this->user, 'edit', '*')->isAbstained())->toBeTrue();

    $this->bouncer->allow($this->user)->everything();

    expect($resolver->resolve($this->user, 'edit', '*')->isGranted())->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('whatever', $account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('anything'))->toBeTrue();
});

it('versions strict no-tenant checks by the global counter', function (): void {
    config()->set('bouncer.scope.null_behavior', 'strict');

    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reuses stored payloads across container lifecycles', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // New lifecycle, same version: the payload comes from the store, not the db.
    Grant::query()->withoutGlobalScopes()->delete();
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('discards payloads whose grant list is corrupted', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    Cache::store('array')->put(cachedPayloadKey($this->user), ['v' => 2, 'grants' => 'junk'], 60);
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('skips grants whose permission is invisible to the current filter', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('ghost');

    // A global grant pointing at a tenant-1 permission: visible rows, filtered catalog.
    Grant::query()->withoutGlobalScopes()->update(['scope' => null]);
    $this->bouncer->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('ghost'))->toBeFalse();
});

it('bounds per-instance memoization for long-lived workers', function (): void {
    $resolver = app(Resolver::class);

    foreach (range(1, 257) as $i) {
        $resolver->resolve(User::query()->create(['name' => "U{$i}"]), 'anything');
    }

    expect($resolver->resolve($this->user, 'anything')->isAbstained())->toBeTrue();
});

it('keys payloads by the full read shape, tenant identity included', function (): void {
    $versioner = app(CacheKeyVersioner::class);

    $this->bouncer->tenant()->to(5);
    expect($versioner->segment())->toStartWith('t5.c1.');

    $this->bouncer->tenant()->onlyRelations();
    expect($versioner->segment())->toStartWith('t5.c0.');

    $this->bouncer->tenant()->onlyRelations(false)->remove();
    expect($versioner->segment())->toStartWith('all.c1.');

    config()->set('bouncer.scope.null_behavior', 'strict');
    expect($versioner->segment())->toStartWith('strict.c1.');
});

it('rebuilds the payload when catalog visibility changes', function (): void {
    // A global grant pointing at a tenant-1 permission: only visible while
    // the catalog is unscoped (onlyRelations).
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('ghost');
    Grant::query()->withoutGlobalScopes()->update(['scope' => null]);

    $this->bouncer->tenant()->to(2);
    $this->bouncer->tenant()->onlyRelations();

    expect(Gate::forUser($this->user)->allows('ghost'))->toBeTrue();

    // Same tenant, catalog scoped again: a different key, never a stale hit.
    $this->bouncer->tenant()->onlyRelations(false);

    expect(Gate::forUser($this->user)->allows('ghost'))->toBeFalse();
});

it('invalidates writes made while the cache is disabled', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // The window runs on the database engine, but its writes must still
    // orphan payloads cached before it.
    config()->set('bouncer.cache.enabled', false);
    $this->bouncer->disallow($this->user)->to('edit-site');
    config()->set('bouncer.cache.enabled', true);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('bumps again after commit for writes inside a transaction', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $before = Cache::store('array')->get('bouncer:v:g');

    DB::transaction(function (): void {
        $this->bouncer->disallow($this->user)->to('edit-site');
    });

    // Once inside the transaction, once after commit: a payload rebuilt by a
    // concurrent reader from pre-commit rows gets orphaned too.
    expect(Cache::store('array')->get('bouncer:v:g'))->toBe($before + 2)
        ->and(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('applies config-backed tenancy splits on fresh lifecycles', function (): void {
    config()->set('bouncer.scope.only_relations', true);
    config()->set('bouncer.scope.role_grants', false);
    app()->forgetScopedInstances();

    expect(app(Tenancy::class)->scopesCatalog())->toBeFalse()
        ->and(app(Tenancy::class)->scopesRoleGrants())->toBeFalse();
});
