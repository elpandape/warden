<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cancellable pre-action (opt-in via bouncer.cancellable_events): a listener
 * returning false aborts the assignment before anything is written.
 */
final readonly class AssigningRole
{
    use Dispatchable;

    /**
     * @param  list<string|Model>  $roles
     * @param  list<Model>  $authorities
     */
    public function __construct(
        public array $roles,
        public array $authorities,
        public int|string|null $scope,
    ) {}
}
