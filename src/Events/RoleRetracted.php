<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: role assignments were removed.
 */
final readonly class RoleRetracted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, Model>  $roles
     */
    public function __construct(
        public Model $authority,
        public Collection $roles,
        public int|string|null $scope,
        public ?Model $restrictedTo = null,
    ) {}
}
