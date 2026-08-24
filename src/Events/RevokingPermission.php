<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cancellable pre-action (opt-in via warden.cancellable_events): a listener
 * returning false aborts the removal before any row is deleted.
 */
final readonly class RevokingPermission
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
