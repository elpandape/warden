<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use Illuminate\Support\Str;

/**
 * Names the operation that the writes and events in progress belong to. It is
 * neither a transaction nor a cache boundary: it holds nothing back and rolls
 * nothing back.
 *
 * @internal
 */
final class Operations
{
    private int $depth = 0;

    private ?string $current = null;

    /**
     * @template T
     *
     * @param  callable(string): T  $work
     * @return T
     */
    public function during(callable $work, ?string $resume = null): mixed
    {
        $id = $this->current ??= $resume ?? (string) Str::ulid();
        $this->depth++;

        try {
            return $work($id);
        } finally {
            if (--$this->depth === 0) {
                $this->current = null;
            }
        }
    }

    public function current(): ?string
    {
        return $this->current;
    }
}
