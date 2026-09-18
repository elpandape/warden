<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use ElPandaPe\Warden\Events\Concerns\QueuesWithoutRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: permissions were explicitly forbidden.
 */
final readonly class PermissionForbidden
{
    use Dispatchable;
    use QueuesWithoutRelations, SerializesModels {
        QueuesWithoutRelations::__serialize insteadof SerializesModels;
    }

    /**
     * @param  Collection<int, Model>  $permissions
     * @param  list<GrantChange>  $grants
     */
    public function __construct(
        public ?Model $authority,
        public Collection $permissions,
        public int|string|null $scope,
        public ?Model $actor = null,
        public array $grants = [],
        public ?string $operation = null,
    ) {}
}
