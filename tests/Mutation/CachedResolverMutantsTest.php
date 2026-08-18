<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Checks\Resolvers\CachedResolver;
use ElPandaPe\Bouncer\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Bouncer\Checks\Resolvers\DatabaseResolver;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Models\AssignedRole;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

function mutantPayloadKey(User $authority): string
{
    return implode(':', [
        'bouncer',
        'p2',
        app(CacheKeyVersioner::class)->segment(),
        $authority->getMorphClass(),
        (string) $authority->getKey(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mutantGrantTuple(array $overrides = []): array
{
    return array_merge([
        'key' => 1,
        'name' => 'edit',
        'entity_type' => null,
        'entity_id' => null,
        'only_owned' => false,
        'forbidden' => false,
        'options' => null,
        'restricted_to_type' => null,
        'restricted_to_id' => null,
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $grants
 */
function seedMutantPayload(User $authority, array $grants): void
{
    Cache::store('array')->put(mutantPayloadKey($authority), ['v' => 2, 'grants' => $grants], 60);
}

beforeEach(function (): void {
    migrateBouncerTables();
    config()->set('bouncer.cache.enabled', true);

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

// Kills 14f7e94348430c14 (resolve, RemoveEarlyReturn): with the cache disabled
// the resolver must pass through to the database engine without ever writing
// a payload into the store.
it('never touches the store while the cache is disabled', function (): void {
    config()->set('bouncer.cache.enabled', false);

    $this->bouncer->allow($this->user)->to('fly');

    expect(app(Resolver::class)->resolve($this->user, 'fly')->isGranted())->toBeTrue()
        ->and(Cache::store('array')->get(mutantPayloadKey($this->user)))->toBeNull();
});

// Kills 61a4ff4e0f9a62f6 (resolve, RemoveEarlyReturn): a non-class entity
// string belongs to app policies. Without the abstain, a wildcard grant in
// the payload would wrongly satisfy the check.
it('abstains on non-class strings even when a wildcard grant exists', function (): void {
    $this->bouncer->allow($this->user)->everything();

    expect(app(Resolver::class)->resolve($this->user, 'edit', 'not-a-class')->isAbstained())->toBeTrue();
});

// Kills d34e24cb1eed80c6 (payload, RemoveEarlyReturn): repeat checks in one
// lifecycle come from the memo, so wiping the store and the rows behind the
// resolver's back must not change the answer.
it('serves repeat checks from the memo, not from the store', function (): void {
    $this->bouncer->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Cache::store('array')->forget(mutantPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

// Kills f2fea9c112a4d743 (payload, IfNegated) and 985ddc0bb9e23338
// (GreaterOrEqualToSmaller): loading another authority below the memo limit
// must not reset already memoized payloads.
it('keeps memoized payloads while under the memo limit', function (): void {
    $this->bouncer->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Cache::store('array')->forget(mutantPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    // A second authority misses the memo: under the mutants that wipes it.
    $resolver->resolve(User::query()->create(['name' => 'Ana']), 'fly');

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

// Kills bfe6301585c888bd (payload, GreaterOrEqualToGreater): the memo resets
// exactly when it holds MEMO_LIMIT entries, not one load later. After the
// reset the stale entry is gone and the check rebuilds from the empty rows.
it('resets the memo exactly at the limit', function (): void {
    $this->bouncer->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);
    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    // Fill the memo up to exactly 256 entries (this user plus 255 more).
    foreach (range(2, 256) as $i) {
        $resolver->resolve(User::query()->create(['name' => "U{$i}"]), 'fly');
    }

    Cache::store('array')->forget(mutantPayloadKey($this->user));
    Grant::query()->withoutGlobalScopes()->delete();

    // The 257th load sees a full memo and must reset it.
    $resolver->resolve(User::query()->create(['name' => 'U257']), 'fly');

    expect($resolver->resolve($this->user, 'fly')->isAbstained())->toBeTrue();
});

// Kills 501f1f67430d56bb (payload, RemoveEarlyReturn): a valid stored payload
// answers directly, without going anywhere near the build lock. With the lock
// held by someone else, falling through would rebuild from the deleted rows.
it('returns a valid stored payload without taking the build lock', function (): void {
    $this->bouncer->allow($this->user)->to('fly');
    expect(app(Resolver::class)->resolve($this->user, 'fly')->isGranted())->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();
    Cache::store('array')->getStore()->lock(mutantPayloadKey($this->user).':lock', 60)->get();

    $resolver = new CachedResolver(
        new DatabaseResolver(Context::resolve()),
        Context::resolve(),
        app(CacheKeyVersioner::class),
        lockWaitSeconds: 0,
    );

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue();
});

// Kills d581985556fa4819 (buildLocked, InstanceOfToFalse): on stores that can
// lock, a cold rebuild must actually request the stampede lock.
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
    config()->set('bouncer.cache.store', 'spy');

    $this->bouncer->allow($this->user)->to('fly');

    expect(Gate::forUser($this->user)->allows('fly'))->toBeTrue()
        ->and($store->lockCalls)->toBeGreaterThan(0);
});

// Kills b2ef0ea43026c186 (buildLocked, RemoveEarlyReturn): the tuples built
// under the lock are the result. Falling through would build the payload a
// second time, doubling the cold-check queries.
it('builds a cold payload exactly once', function (): void {
    $this->bouncer->allow($this->user)->to('fly');

    $resolver = app(Resolver::class);

    DB::enableQueryLog();

    expect($resolver->resolve($this->user, 'fly')->isGranted())->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(3);

    DB::disableQueryLog();
});

// Kills 2745b9dbd0de1d85 (build, RemoveNot): string role keys (UUID/ULID role
// setups) must stay usable in the cached payload, not get dropped.
it('authorizes through roles whose keys are strings', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->bouncer->allow('editor')->to('edit', Account::class);
    $this->bouncer->assign('editor')->to($this->user);

    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('assigned_roles')->update(['role_id' => 'role-uuid']);
    DB::table('grants')->update(['entity_id' => 'role-uuid']);
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $account))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

// Kills 22fbc38db7230eda (build, ContinueToBreak): a half-written restriction
// row is skipped; it must not stop the scan before later, healthy assignments.
it('keeps scanning assignments after a half-written restriction row', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->bouncer->allow('editor')->to('edit', Account::class);
    $this->bouncer->assign('editor')->on($org)->to($this->user);
    $this->bouncer->assign('editor')->to($this->user);

    // Corrupt the first (restricted) row into the half-written shape.
    AssignedRole::query()->whereNotNull('restricted_to_type')->update(['restricted_to_id' => null]);
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue();
});

// Kills 4532521d276934c7 and e699b10bf83bf222 (build, BooleanAndToBooleanOr):
// a grant held directly counts as via-role only when its entity really is a
// role. Here the first role's key collides numerically with the authority's
// key, so the mutants staple the role's restriction onto the direct grant.
it('keeps direct grants unrestricted when a role key matches the authority key', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->bouncer->assign('manager')->on($org)->to($this->user);
    $this->bouncer->allow($this->user)->to('fly');

    // The collision the mutants depend on: role key === authority key.
    expect(Role::query()->sole()->getKey())->toBe($this->user->getKey())
        ->and(Gate::forUser($this->user)->allows('fly'))->toBeTrue();
});

// Kills 549f79c5ac51cc75 (build, RemoveArrayItem on the forbidden segment):
// without it, a forbid and a grant of the same permission collide in the
// dedupe key and the later row silently erases the forbid.
it('keeps forbid and allow rows of one permission apart in the payload', function (): void {
    $this->bouncer->forbid($this->user)->to('publish');
    $this->bouncer->allow($this->user)->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

// Kills 4f9a2843abb5b1e4 (CoalesceRemoveLeft) and 3e66f6e25be42f88
// (RemoveArrayItem) on the context-type segment: two restrictions of the same
// role sharing an id but not a type must both survive deduplication.
it('keeps restrictions of different context types with the same id apart', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    // Same-id contexts of different types: Account#1 and User#1.
    expect($org->getKey())->toBe($this->user->getKey());

    $this->bouncer->allow('editor')->to('edit', Account::class);
    $this->bouncer->assign('editor')->on($org)->to($this->user);
    $this->bouncer->assign('editor')->on($this->user)->to($this->user);

    $project = Account::query()->create(['name' => 'Project', 'account_id' => $org->getKey()])->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();
});

// Kills 579cb6ef86a7c821 (TernaryNegated), 59ef22b550b89eb3
// (IdenticalToNotIdentical) and f5091120b78b5b4c (RemoveArrayItem) on the
// context-id segment, plus c8fb69080e65b9f0 (RemoveArrayItem on the tuple
// destructure): two restrictions of the same type with different ids must
// each keep their own tuple, with their own id.
it('keeps restrictions of one type with different context ids apart', function (): void {
    $orgOne = Account::query()->create(['name' => 'Org One'])->refresh();
    $orgTwo = Account::query()->create(['name' => 'Org Two'])->refresh();

    $this->bouncer->allow('editor')->to('edit', Account::class);
    $this->bouncer->assign('editor')->on($orgOne)->to($this->user);
    $this->bouncer->assign('editor')->on($orgTwo)->to($this->user);

    $project = Account::query()->create(['name' => 'Project', 'account_id' => $orgOne->getKey()])->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();
});

// Kills 834adbc48ba0635f (build, ContinueToBreak): a grant whose permission
// row is gone is skipped; it must not stop the scan before later grants.
it('skips grants with missing permission rows without stopping the scan', function (): void {
    $this->bouncer->allow($this->user)->to('ghost');
    $this->bouncer->allow($this->user)->to('real');

    // Orphan the first grant: its permission row disappears, the row stays.
    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('permissions')->where('name', 'ghost')->delete();
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('real'))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

// Kills cb6dc82e88fe2d37 (firstMatch, ContinueToBreak): an unowned only-owned
// tuple is skipped; later tuples must still be considered.
it('skips unowned only-owned tuples without stopping the match', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['key' => 11, 'only_owned' => true]),
        mutantGrantTuple(['key' => 12]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(12);
});

// Kills 02cdb77db485c70d (firstMatch, ContinueToBreak): an out-of-context
// restricted tuple is skipped; later tuples must still be considered.
it('skips out-of-context tuples without stopping the match', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['key' => 21, 'restricted_to_type' => 'some-context', 'restricted_to_id' => 9]),
        mutantGrantTuple(['key' => 22]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(22);
});

// Kills 7fe099d3207cac71 (firstMatch, BooleanAndToBooleanOr): a tuple is
// restricted only when BOTH context fields are set. A stray id alone (a shape
// the builder never emits but a cache payload could carry) must not fail the
// check closed.
it('treats a tuple as restricted only when both context fields are set', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['restricted_to_id' => 7]),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit')->isGranted())->toBeTrue();
});

// Kills a09b5062212c683d (RemoveFunctionCall on usort), 85e1a2c0e1a9f7e8
// (SpaceshipSwitchSides), fad630341d715380 (TernaryNegated),
// d1c308a408cbe1f4 and 70b13f4329ab9382 (NotIdenticalToIdentical): instance
// tuples outrank class tuples regardless of payload order.
it('prefers instance tuples over class tuples', function (): void {
    $account = Account::query()->create(['name' => 'Plain'])->refresh();

    seedMutantPayload($this->user, [
        mutantGrantTuple(['key' => 101, 'entity_type' => $account->getMorphClass()]),
        mutantGrantTuple(['key' => 202, 'entity_type' => $account->getMorphClass(), 'entity_id' => $account->getKey()]),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'edit', $account);

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(202);
});

// Kills 3d8e561336bfbdfd and 4f65acae6df236eb (NotIdenticalToIdentical) and
// 69abe7c85f91f8a6 (SpaceshipSwitchSides) on the type tie-break: with equal
// id specificity, the typed wildcard shape outranks the plain simple tuple.
it('breaks id-specificity ties by entity type', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['key' => 301, 'name' => '*']),
        mutantGrantTuple(['key' => 302, 'name' => '*', 'entity_type' => '*']),
    ]);

    $verdict = app(Resolver::class)->resolve($this->user, 'anything');

    expect($verdict->isGranted())->toBeTrue()
        ->and($verdict->permissionKey)->toBe(302);
});

// Kills fa85b70e8e73ab69 (matchesEntity, BooleanAndToBooleanOr): a named
// permission granted on every entity type must not satisfy a simple check.
it('keeps blanket-entity grants away from simple checks', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['entity_type' => '*']),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit')->isAbstained())->toBeTrue();
});

// Kills 9394395f9117065e (IdenticalToNotIdentical) and 04fd832f6651f901
// (BooleanAndToBooleanOr) on the class-check shape: a grant on one specific
// instance must not satisfy a class-level check.
it('keeps instance grants away from class-level checks', function (): void {
    seedMutantPayload($this->user, [
        mutantGrantTuple(['entity_type' => (new Account)->getMorphClass(), 'entity_id' => 5]),
    ]);

    expect(app(Resolver::class)->resolve($this->user, 'edit', Account::class)->isAbstained())->toBeTrue();
});
