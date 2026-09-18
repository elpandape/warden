<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use ElPandaPe\Warden\Events\Concerns\QueuesWithoutRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: granted permissions were removed.
 */
final readonly class PermissionRevoked
{
    use Dispatchable;
    use QueuesWithoutRelations, SerializesModels {
        QueuesWithoutRelations::__serialize insteadof SerializesModels;
    }

    /**
     * @param  Collection<int, Model>  $permissions
     * @param  list<GrantRemoval>  $grants
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
