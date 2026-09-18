<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * A store that still reads and writes, but whose counters cannot move: an
 * outage caught halfway through a write.
 */
final class FailingIncrementCacheStore extends ArrayStore
{
    public function increment($key, $value = 1): never
    {
        throw new RuntimeException('The cache store is down.');
    }
}
