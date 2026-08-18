<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Post-action: a declarative role sync ran, with its full diff.
 */
final readonly class RolesSynced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $authority,
        public SyncResult $changes,
        public int|string|null $scope,
    ) {}
}
