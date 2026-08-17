<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: roles were assigned. The restriction context stays null
 * until restricted roles land in v0.8.
 */
final readonly class RoleAssigned
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
