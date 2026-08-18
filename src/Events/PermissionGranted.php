<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Post-action: permissions were granted. A null authority means "everyone".
 */
final readonly class PermissionGranted
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
