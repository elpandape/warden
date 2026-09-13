<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a model save changed the role's snapshot; $changed lists
 * the keys that differ, in snapshot order.
 *
 * @phpstan-import-type RoleShape from RoleSnapshot
 */
final readonly class RoleUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  RoleShape  $before
     * @param  RoleShape  $after
     * @param  list<string>  $changed
     */
    public function __construct(
        public Model $role,
        public array $before,
        public array $after,
        public array $changed,
        public ?Model $actor = null,
    ) {}
}
