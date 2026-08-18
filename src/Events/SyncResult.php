<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The diff a sync produced: what it created, what it removed, what it kept.
 */
final readonly class SyncResult
{
    /**
     * @param  Collection<int, Model>  $attached
     * @param  Collection<int, Model>  $detached
     * @param  Collection<int, Model>  $kept
     */
    public function __construct(
        public Collection $attached,
        public Collection $detached,
        public Collection $kept,
    ) {}
}
