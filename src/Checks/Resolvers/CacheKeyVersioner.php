<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks\Resolvers;

use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/**
 * O(1) invalidation through version counters baked into every cache key:
 * bumping a counter orphans the stale entries instead of hunting them down.
 *
 * Three counters cover the three read shapes: 'g' (global rows, seen by every
 * shape), one per tenant, and 'a' (bumped on every write — backs the
 * no-tenant "sees everything" shape).
 */
final readonly class CacheKeyVersioner
{
    public function __construct(private Factory $cache) {}

    /**
     * Record a write into the given exact scope.
     */
    public function bump(int|string|null $scope): void
    {
        // Unconditional: a write during a disabled-cache window must still
        // orphan payloads cached before it, or re-enabling serves stale rules.
        $this->increment('a');
        $this->increment($scope === null ? 'g' : "t.{$scope}");
    }

    /**
     * Invalidate every cached payload at once.
     */
    public function refreshAll(): void
    {
        $this->bump(null);
    }

    /**
     * The version segment for the current read filter, embedded in cache keys.
     * It names the full read shape — tenant identity and catalog visibility
     * included — so payloads built under different shapes never share a key.
     */
    public function segment(): string
    {
        $tenancy = app(Tenancy::class);
        $filter = $tenancy->readFilter();
        $catalog = $tenancy->scopesCatalog() ? 'c1' : 'c0';

        if ($filter === null) {
            return "all.{$catalog}.a".$this->counter('a');
        }

        if ($filter[0] === 'null') {
            return "strict.{$catalog}.g".$this->counter('g');
        }

        return "t{$filter[1]}.{$catalog}.g".$this->counter('g').'.v'.$this->counter("t.{$filter[1]}");
    }

    public function store(): Repository
    {
        return $this->cache->store(Config::cacheStore());
    }

    private function increment(string $name): void
    {
        // Seed before bumping so a freshly (re)created counter never restarts
        // at a value an evicted predecessor may have already handed out.
        $this->counter($name);
        $this->store()->increment($this->key($name));
    }

    private function counter(string $name): int
    {
        $key = $this->key($name);
        $value = $this->store()->get($key);

        if (! is_int($value)) {
            // Random seed: if the counter is ever evicted, restarting from a
            // fixed value could resurrect stale payloads under old keys.
            $this->store()->add($key, random_int(1, 1_000_000_000));
            $value = $this->store()->get($key);
        }

        return is_int($value) ? $value : 0;
    }

    private function key(string $name): string
    {
        return Config::cachePrefix().':v:'.$name;
    }
}
