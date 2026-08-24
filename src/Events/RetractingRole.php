<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cancellable pre-action (opt-in via warden.cancellable_events): a listener
 * returning false aborts the removal before any row is deleted.
 *
 * Covers the whole call, like its assigning counterpart: a listener vetoes
 * every authority in it or none.
 */
final readonly class RetractingRole
{
    use Dispatchable;

    /**
     * @param  list<mixed>  $roles
     * @param  list<Model>  $authorities
     */
    public function __construct(
        public array $roles,
        public array $authorities,
        public int|string|null $scope,
        public ?Model $restrictedTo = null,
    ) {}
}
