<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cancellable pre-action (opt-in via bouncer.cancellable_events): a listener
 * returning false aborts the operation before anything is written.
 */
final readonly class ForbiddingPermission
{
    use Dispatchable;

    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public Model|string|null $authority,
        public array $permissions,
        public Model|string|null $entity,
        public int|string|null $scope,
        public bool $onlyOwned = false,
    ) {}
}
