<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: role assignments were removed. $restrictedTo is the context
 * the call named with on(), null when it named none; each entry in
 * $assignments carries the context its own row had.
 */
final readonly class RoleRetracted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, Model>  $roles
     * @param  list<AssignmentRemoval>  $assignments
     */
    public function __construct(
        public Model $authority,
        public Collection $roles,
        public int|string|null $scope,
        public ?Model $restrictedTo = null,
        public ?Model $actor = null,
        public array $assignments = [],
    ) {}
}
