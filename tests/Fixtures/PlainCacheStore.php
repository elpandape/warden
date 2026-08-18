<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;

/**
 * A Store WITHOUT LockProvider: exercises the no-lock rebuild path.
 */
final class PlainCacheStore implements Store
{
    /** @var array<string, mixed> */
    private array $storage = [];

    public function get($key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->storage[$key] = $value;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $current = $this->storage[$key] ?? 0;
        $this->storage[$key] = (is_int($current) ? $current : 0) + (is_int($value) ? $value : 1);

        return $this->storage[$key];
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, -(is_int($value) ? $value : 1));
    }

    public function touch($key, $seconds): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        unset($this->storage[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
