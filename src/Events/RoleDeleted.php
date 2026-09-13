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
 * The row is gone before any queued listener runs, so the event travels by
 * value instead of as an identifier to read back. SerializesModels stays:
 * dropping it would take its public methods with it.
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

    /**
     * @return array{role: Model, actor: Model|null, heldGrants: list<HeldGrant>, heldRoles: list<HeldRole>}
     */
    public function __serialize(): array
    {
        return [
            'role' => $this->role,
            'actor' => $this->actor,
            'heldGrants' => $this->heldGrants,
            'heldRoles' => $this->heldRoles,
        ];
    }

    /**
     * @param  array{role: Model, actor: Model|null, heldGrants: list<HeldGrant>, heldRoles: list<HeldRole>}  $values
     */
    public function __unserialize(array $values): void
    {
        $this->role = $values['role'];
        $this->actor = $values['actor'];
        $this->heldGrants = $values['heldGrants'];
        $this->heldRoles = $values['heldRoles'];
    }
}
