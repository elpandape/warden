<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CachedResolver;
use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BarePivot;
use ElPandaPe\Warden\Tests\Fixtures\CustomRole;
use ElPandaPe\Warden\Tests\Fixtures\PlainCacheStore;
use ElPandaPe\Warden\Tests\Fixtures\ScopedGrant;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\cachedPayloadKey;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();
    config()->set('warden.cache.enabled', true);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('answers every grant shape like the database engine', function (): void {
    $account = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $foreign = Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allow($this->user)->to('ban-users');
    $this->warden->allow($this->user)->to('edit', Account::class);
    $this->warden->allow($this->user)->toOwn(Account::class, ['delete']);
    $this->warden->allow('admin')->to('audit');
    $this->warden->assign('admin')->to($this->user);
    $this->warden->allowEveryone()->to('browse');
    $this->warden->forbid($this->user)->to('edit', $foreign);

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
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    DB::enableQueryLog();

    // Every check after the first must cost zero queries, whatever the permission.
    foreach (range(1, 25) as $i) {
        Gate::forUser($this->user)->allows('edit-site');
        Gate::forUser($this->user)->allows('other-permission');
    }

    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('keeps serving the cached payload when rows change behind its back', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A raw delete never bumps the version: the payload stays, by design.
    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reflects every action immediately through version bumps', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $this->warden->disallow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->warden->assign('admin')->to($this->user);
    $this->warden->allow('admin')->to('audit');
    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();

    $this->warden->retract('admin')->from($this->user);
    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();

    $this->warden->sync($this->user)->permissions(['publish']);
    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();

    $this->warden->forbid($this->user)->to('publish');
    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();

    $this->warden->unforbid($this->user)->to('publish');
    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('keeps tenant payloads independent through per-tenant versions', function (): void {
    $this->warden->tenant()->to(2);
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A write in tenant 1 must not invalidate tenant 2's cached payload:
    // the raw delete is only visible once something bumps tenant 2.
    Grant::query()->withoutGlobalScopes()->where('scope', 2)->delete();
    $this->warden->tenant()->onceTo(1, function (): void {
        $this->warden->allow($this->user)->to('other');
    });

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // A global write bumps every shape, so the stale payload is rebuilt.
    $this->warden->tenant()->removeOnce(function (): void {
        $this->warden->allowEveryone()->to('browse');
    });

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('invalidates everything with refresh', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('invalidates one authority with refreshFor', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    $this->warden->refreshFor($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('ignores refreshFor when the cache is disabled', function (): void {
    config()->set('warden.cache.enabled', false);
    $this->warden->allow($this->user)->to('edit-site');

    // Passthrough mode: the database engine answers and refreshFor is a no-op.
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and($this->warden->refreshFor($this->user))->toBe($this->warden);

    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('expires payloads after the configured ttl', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    app()->forgetScopedInstances();
    $this->travel(25)->hours();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->travelBack();
});

it('stores a versioned payload with the fields v0.8 will need', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    $payload = Cache::store('array')->get(cachedPayloadKey($this->user));

    expect($payload)->toBeArray()
        ->and($payload['v'])->toBe(4)
        ->and($payload['grants'][0])->toHaveKeys([
            'key', 'name', 'entity_type', 'entity_id', 'only_owned',
            'forbidden', 'options', 'restricted_to_type', 'restricted_to_id', 'expires_at',
        ]);
});

it('discards cached payloads from other payload versions', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    // A payload written by a different package version must be rebuilt.
    Cache::store('array')->put(cachedPayloadKey($this->user), ['v' => 0, 'grants' => []], 60);
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('falls back to a direct rebuild when the stampede lock times out', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    $resolver = new CachedResolver(
        app(Resolver::class),
        Context::resolve(),
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
    config()->set('warden.cache.store', 'plain');

    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // Cached: a raw delete stays invisible until a bump.
    Grant::query()->withoutGlobalScopes()->delete();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reseeds corrupted version counters at random', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    Cache::store('array')->put('warden:v:a', 'junk', 60);
    Cache::store('array')->put('warden:v:g', 'junk', 60);
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

    $this->warden->allow($this->user)->to('edit', Account::class);

    $resolver = app(Resolver::class);

    expect(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse()
        ->and($resolver->resolve($this->user, 'edit', '*')->isAbstained())->toBeTrue();

    $this->warden->allow($this->user)->everything();

    expect($resolver->resolve($this->user, 'edit', '*')->isGranted())->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('whatever', $account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('anything'))->toBeTrue();
});

it('versions strict no-tenant checks by the global counter', function (): void {
    config()->set('warden.scope.null_behavior', 'strict');

    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('reuses stored payloads across container lifecycles', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // New lifecycle, same version: the payload comes from the store, not the db.
    Grant::query()->withoutGlobalScopes()->delete();
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('discards payloads whose grant list is corrupted', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    Gate::forUser($this->user)->allows('edit-site');

    Cache::store('array')->put(cachedPayloadKey($this->user), ['v' => 4, 'grants' => 'junk'], 60);
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('skips grants whose permission is invisible to the current filter', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('ghost');

    // A global grant pointing at a tenant-1 permission: visible rows, filtered catalog.
    Grant::query()->withoutGlobalScopes()->update(['scope' => null]);
    $this->warden->tenant()->to(2);

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

    $this->warden->tenant()->to(5);
    expect($versioner->segment())->toStartWith('t5.c1.');

    $this->warden->tenant()->onlyRelations();
    expect($versioner->segment())->toStartWith('t5.c0.');

    $this->warden->tenant()->onlyRelations(false)->remove();
    expect($versioner->segment())->toStartWith('all.c1.');

    config()->set('warden.scope.null_behavior', 'strict');
    expect($versioner->segment())->toStartWith('strict.c1.');
});

it('rebuilds the payload when catalog visibility changes', function (): void {
    // A global grant pointing at a tenant-1 permission: only visible while
    // the catalog is unscoped (onlyRelations).
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('ghost');
    Grant::query()->withoutGlobalScopes()->update(['scope' => null]);

    $this->warden->tenant()->to(2);
    $this->warden->tenant()->onlyRelations();

    expect(Gate::forUser($this->user)->allows('ghost'))->toBeTrue();

    // Same tenant, catalog scoped again: a different key, never a stale hit.
    $this->warden->tenant()->onlyRelations(false);

    expect(Gate::forUser($this->user)->allows('ghost'))->toBeFalse();
});

it('invalidates writes made while the cache is disabled', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // The window runs on the database engine, but its writes must still
    // orphan payloads cached before it.
    config()->set('warden.cache.enabled', false);
    $this->warden->disallow($this->user)->to('edit-site');
    config()->set('warden.cache.enabled', true);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('bumps again after commit for writes inside a transaction', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $before = Cache::store('array')->get('warden:v:g');

    DB::transaction(function (): void {
        $this->warden->disallow($this->user)->to('edit-site');
    });

    // Once inside the transaction, once after commit: a payload rebuilt by a
    // concurrent reader from pre-commit rows gets orphaned too.
    expect(Cache::store('array')->get('warden:v:g'))->toBe($before + 2)
        ->and(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('applies config-backed tenancy splits on fresh lifecycles', function (): void {
    config()->set('warden.scope.only_relations', true);
    config()->set('warden.scope.role_grants', false);
    app()->forgetScopedInstances();

    expect(app(Tenancy::class)->scopesCatalog())->toBeFalse()
        ->and(app(Tenancy::class)->scopesRoleGrants())->toBeFalse();
});

it('leaves the cache version alone when a write changes nothing', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $version = Cache::store('array')->get('warden:v:a');

    $this->warden->allow($this->user)->to('edit-site');

    expect(Cache::store('array')->get('warden:v:a'))->toBe($version);
});

it('invalidates cached checks through a pivot model warden does not own', function (): void {
    config()->set('warden.models.grant', BarePivot::class);
    app()->forgetInstance(Context::class);

    $permission = Permission::query()->create(['name' => 'edit-site']);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->user->permissions()->attach($permission);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $this->user->permissions()->detach($permission);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('invalidates cached checks when a catalog row is edited by the model', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Permission::query()->where('name', 'edit-site')->sole()->update(['name' => 'renamed']);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('renamed'))->toBeTrue();
});

it('invalidates cached checks when a condition is edited by the model', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Other');

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();

    Permission::query()->where('name', 'view')->sole()->update([
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Acme']]]]],
    ]);

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeTrue();
});

it('invalidates cached checks in the tenants a cascade reached', function (): void {
    $this->warden->tenant()->to(5);
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    // The catalog row is global, so it is deleted from outside the tenant —
    // and the foreign key takes tenant 5's grant with it.
    $this->warden->tenant()->remove();
    Permission::query()->withoutGlobalScopes()->where('name', 'edit-site')->sole()->delete();

    $this->warden->tenant()->to(5);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('sweeps the grants a deleted role held, which no foreign key reaches', function (): void {
    $this->warden->allow('editor')->to('edit-site');

    $role = Role::query()->where('name', 'editor')->sole();
    $held = fn (): int => Grant::query()->withoutGlobalScopes()
        ->where('entity_type', $role->getMorphClass())
        ->where('entity_id', $role->getKey())
        ->count();

    expect($held())->toBe(1);

    $role->delete();

    expect($held())->toBe(0);
});

it('honours a global scope on a swapped grant model, cached or not', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    config()->set('warden.models.grant', ScopedGrant::class);
    app()->forgetInstance(Context::class);
    app()->forgetInstance(Resolver::class);
    $this->warden->refresh();

    $cached = Gate::forUser($this->user)->allows('edit-site');

    config()->set('warden.cache.enabled', false);
    $uncached = Gate::forUser($this->user)->allows('edit-site');

    expect($cached)->toBeFalse()
        ->and($uncached)->toBeFalse();
});

it('invalidates and sweeps a deleted role before a throwing listener can stop it', function (): void {
    withForeignKeys();

    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();

    $role = Role::query()->where('name', 'editor')->sole();

    Event::listen(RoleDeleted::class, function (): void {
        throw new RuntimeException('role listener failed');
    });

    expect(fn (): ?bool => $role->delete())->toThrow(RuntimeException::class, 'role listener failed')
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and(Grant::query()->withoutGlobalScopes()
            ->where('entity_type', $role->getMorphClass())
            ->where('entity_id', $role->getKey())
            ->count())->toBe(0);
});

it('invalidates a deleted permission before a throwing listener and loses only its cascade announcement', function (): void {
    withForeignKeys();

    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $revoked = 0;
    Event::listen(PermissionRevoked::class, function () use (&$revoked): void {
        $revoked++;
    });
    Event::listen(PermissionDeleted::class, function (): void {
        throw new RuntimeException('permission listener failed');
    });

    $permission = Permission::query()->where('name', 'edit-site')->sole();

    expect(fn (): ?bool => $permission->delete())->toThrow(RuntimeException::class, 'permission listener failed')
        ->and(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and(Permission::query()->count())->toBe(0)
        ->and(Grant::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($revoked)->toBe(0);
});

it('leaves the grants of a role class warden is not configured with to warden:clean', function (): void {
    withForeignKeys();

    $outsider = CustomRole::query()->create(['name' => 'outsider']);
    $this->warden->allow($outsider)->to('publish');

    $outsider->delete();

    expect(Grant::query()->withoutGlobalScopes()->where('entity_type', $outsider->getMorphClass())->count())->toBe(1);
});

it('keeps the deprecated markCascade settling and announcing a cascade in one call', function (): void {
    withForeignKeys();

    $this->warden->allow($this->user)->to('edit-site');
    $permission = Permission::query()->where('name', 'edit-site')->sole();
    $invalidations = app(CacheInvalidations::class);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $invalidations->prepareCascade($permission);
    $permission->deleteQuietly();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Event::fake([PermissionRevoked::class]);

    $invalidations->markCascade($permission);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true);
});
