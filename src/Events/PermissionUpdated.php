<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a model save changed the permission's snapshot; $changed
 * lists the keys that differ, in snapshot order.
 *
 * @phpstan-import-type PermissionShape from PermissionSnapshot
 */
final readonly class PermissionUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  PermissionShape  $before
     * @param  PermissionShape  $after
     * @param  list<string>  $changed
     */
    public function __construct(
        public Model $permission,
        public array $before,
        public array $after,
        public array $changed,
        public ?Model $actor = null,
    ) {}
}
