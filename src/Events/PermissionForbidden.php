<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

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
    use SerializesModels;

    /**
     * @param  Collection<int, Model>  $permissions
     */
    public function __construct(
        public ?Model $authority,
        public Collection $permissions,
        public int|string|null $scope,
    ) {}
}
