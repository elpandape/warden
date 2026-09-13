<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a role row was deleted.
 *
 * @phpstan-import-type PermissionShape from PermissionSnapshot
 * @phpstan-import-type RoleShape from RoleSnapshot
 *
 * @phpstan-type HeldGrant array{permission: PermissionShape, forbidden: bool, scope: int|string|null, expires_at: CarbonImmutable|null}
 * @phpstan-type HeldRole array{role: RoleShape, scope: int|string|null, restricted_to_type: string|null, restricted_to_id: int|string|null, expires_at: CarbonImmutable|null}
 */
final readonly class RoleDeleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<HeldGrant>  $heldGrants
     * @param  list<HeldRole>  $heldRoles
     */
    public function __construct(
        public Model $role,
        public ?Model $actor = null,
        public array $heldGrants = [],
        public array $heldRoles = [],
    ) {}
}
