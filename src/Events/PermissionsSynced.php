<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Post-action: a declarative permission sync ran, with its full diff.
 */
final readonly class PermissionsSynced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $authority,
        public SyncResult $changes,
        public int|string|null $scope,
        public bool $forbidden,
    ) {}
}
