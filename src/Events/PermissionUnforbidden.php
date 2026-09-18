<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: explicit forbids were lifted.
 */
final readonly class PermissionUnforbidden
{
    use Dispatchable;
    use SerializesModels;

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
