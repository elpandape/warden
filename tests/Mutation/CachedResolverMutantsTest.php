<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CachedResolver;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Checks\Resolvers\DatabaseResolver;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\cachedPayloadKey;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\grantTuple;
use function ElPandaPe\Warden\Tests\seedCachedPayload;

beforeEach(function (): void {
    migrateWardenTables();
    config()->set('warden.cache.enabled', true);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('never touches the store while the cache is disabled', function (): void {
    config()->set('warden.cache.enabled', false);

    $this->warden->allow($this->user)->to('fly');

    expect(app(Resolver::class)->resolve($this->user, 'fly')->isGranted())->toBeTrue()
        ->and(Cache::store('array')->get(cachedPayloadKey($this->user)))->toBeNull();
});

it('abstains on non-class strings even when a wildcard grant exists', function (): void {
    $this->warden->allow($this->user)->everything();

    expect(app(Resolver::class)->resolve($this->user, 'edit', 'not-a-class')->isAbstained())->toBeTrue();
});

it('serves repeat checks from the memo, not from the store', function (): void {
    $this->warden->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Cache::store('array')->forget(cachedPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

it('keeps memoized payloads while under the memo limit', function (): void {
    $this->warden->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Cache::store('array')->forget(cachedPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    $resolver->resolve(User::query()->create(['name' => 'Ana']), 'fly');

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

it('resets the memo exactly at the limit', function (): void {
    $this->warden->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    // Fill the memo up to exactly 256 entries (this user plus 255 more).
    foreach (range(2, 256) as $i) {
        $resolver->resolve(User::query()->create(['name' => "U{$i}"]), 'fly');
    }

    Cache::store('array')->forget(cachedPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    // The 257th load sees a full memo and must reset it.
    $resolver->resolve(User::query()->create(['name' => 'U257']), 'fly');

    expect($resolver->resolve($this->user, 'fly')->isAbstained())->toBeTrue();
});

it('returns a valid stored payload without taking the build lock', function (): void {
    $this->warden->allow($this->user)->to('fly');
    expect(app(Resolver::class)->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    Cache::store('array')->getStore()->lock(cachedPayloadKey($this->user).':lock', 60)->get();

    $resolver = new CachedResolver(
        new DatabaseResolver(Context::resolve()),
        Context::resolve(),
        app(CacheKeyVersioner::class),
        lockWaitSeconds: 0,
    );

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

it('guards cold rebuilds with the store lock', function (): void {
    $store = new class extends ArrayStore
    {
        public int $lockCalls = 0;

        public function lock($name, $seconds = 0, $owner = null): ArrayLock
        {
            $this->lockCalls++;

            return parent::lock($name, $seconds, $owner);
        }
    };

    Cache::extend('spy', fn (): Illuminate\Contracts\Cache\Repository => Cache::repository($store));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('warden.cache.store', 'spy');

    $this->warden->allow($this->user)->to('fly');

    expect(Gate::forUser($this->user)->allows('fly'))->toBeTrue()
        ->and($store->lockCalls)->toBeGreaterThan(0);
});

it('builds a cold payload exactly once', function (): void {
    $this->warden->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);

    DB::enableQueryLog();

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(3);

    DB::disableQueryLog();
});

it('authorizes through roles whose keys are strings', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->to($this->user);

    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('assigned_roles')->update(['role_id' => 'role-uuid']);
    DB::table('grants')->update(['entity_id' => 'role-uuid']);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $account))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

it('keeps scanning assignments after a half-written restriction row', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($org)->to($this->user);
    $this->warden->assign('editor')->to($this->user);

    // Corrupt the first (restricted) row into the half-written shape.
    AssignedRole::query()->whereNotNull('restricted_to_type')->update(['restricted_to_id' => null]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue();
});

it('keeps direct grants unrestricted when a role key matches the authority key', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->assign('manager')->on($org)->to($this->user);
    $this->warden->allow($this->user)->to('fly');

    // Force the collision: role key === authority key.
    expect(Role::query()->sole()->getKey())->toBe($this->user->getKey())
        ->and(Gate::forUser($this->user)->allows('fly'))->toBeTrue();
});

it('keeps forbid and allow rows of one permission apart in the payload', function (): void {
    $this->warden->forbid($this->user)->to('publish');
    $this->warden->allow($this->user)->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('keeps restrictions of different context types with the same id apart', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    // Same-id contexts of different types: Account#1 and User#1.
    expect($org->getKey())->toBe($this->user->getKey());

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($org)->to($this->user);
    $this->warden->assign('editor')->on($this->user)->to($this->user);

    $project = Account::query()->create(['name' => 'Project', 'account_id' => $org->getKey()])->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();
});

it('keeps restrictions of one type with different context ids apart', function (): void {
    $orgOne = Account::query()->create(['name' => 'Org One'])->refresh();
    $orgTwo = Account::query()->create(['name' => 'Org Two'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($orgOne)->to($this->user);
    $this->warden->assign('editor')->on($orgTwo)->to($this->user);

    $project = Account::query()->create(['name' => 'Project', 'account_id' => $orgOne->getKey()])->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();
});

it('skips grants with missing permission rows without stopping the scan', function (): void {
    $this->warden->allow($this->user)->to('ghost');
    $this->warden->allow($this->user)->to('real');

    // Orphan the first grant: its permission row disappears, the row stays.
    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('permissions')->where('name', 'ghost')->delete();
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('real'))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

it('skips unowned only-owned tuples without stopping the match', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['key' => 11, 'only_owned' => true]),
        grantTuple(['key' => 12]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(12);
});

it('skips an expired tuple without stopping the match', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['key' => 31, 'expires_at' => Carbon::now()->subSecond()->getTimestamp()]),
        grantTuple(['key' => 32]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(32);
});

it('skips out-of-context tuples without stopping the match', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['key' => 21, 'restricted_to_type' => 'some-context', 'restricted_to_id' => 9]),
        grantTuple(['key' => 22]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(22);
});

it('treats a tuple as restricted only when both context fields are set, not on a stray id alone', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['restricted_to_id' => 7]),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit')->isGranted())->toBeTrue();
});

it('prefers instance tuples over class tuples regardless of payload order', function (): void {
    $account = Account::query()->create(['name' => 'Plain'])->refresh();

    seedCachedPayload($this->user, [
        grantTuple(['key' => 101, 'entity_type' => $account->getMorphClass()]),
        grantTuple(['key' => 202, 'entity_type' => $account->getMorphClass(), 'entity_id' => $account->getKey()]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit', $account);

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(202);
});

it('breaks id-specificity ties by entity type, ranking the typed wildcard above the plain tuple', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['key' => 301, 'name' => '*']),
        grantTuple(['key' => 302, 'name' => '*', 'entity_type' => '*']),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'anything');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(302);
});

it('keeps blanket-entity grants away from simple checks', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['entity_type' => '*']),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit')->isAbstained())->toBeTrue();
});

it('keeps instance grants away from class-level checks', function (): void {
    seedCachedPayload($this->user, [
        grantTuple(['entity_type' => (new Account)->getMorphClass(), 'entity_id' => 5]),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit', Account::class)->isAbstained())->toBeTrue();
});
