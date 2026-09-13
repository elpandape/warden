<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: roles were assigned. The restriction context is the one on()
 * named, null when the call named none.
 */
final readonly class RoleAssigned
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, Model>  $roles
     * @param  list<AssignmentChange>  $assignments
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
