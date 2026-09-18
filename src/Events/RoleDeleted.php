<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Events\Concerns\CarriesActorByIdentifier;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a role row was deleted. $softDeleted says it went to the
 * trash; a force delete, out of the trash included, destroyed it.
 *
 * The row may be gone before any queued listener runs, so it travels by value,
 * without its relations; the actor travels as an identifier.
 *
 * @phpstan-import-type PermissionShape from PermissionSnapshot
 * @phpstan-import-type RoleShape from RoleSnapshot
 *
 * @phpstan-type HeldGrant array{permission: PermissionShape, forbidden: bool, scope: int|string|null, expires_at: CarbonImmutable|null}
 * @phpstan-type HeldRole array{role: RoleShape, scope: int|string|null, restricted_to_type: string|null, restricted_to_id: int|string|null, expires_at: CarbonImmutable|null}
 */
final readonly class RoleDeleted
{
    use CarriesActorByIdentifier;
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
        public bool $softDeleted = false,
        public ?string $operation = null,
    ) {}

    /**
     * @return array{role: Model, actor: ModelIdentifier|null, heldGrants: list<HeldGrant>, heldRoles: list<HeldRole>, softDeleted: bool, operation: string|null}
     */
    public function __serialize(): array
    {
        return [
            'role' => $this->role->withoutRelations(),
            'actor' => $this->actorIdentifier($this->actor),
            'heldGrants' => $this->heldGrants,
            'heldRoles' => $this->heldRoles,
            'softDeleted' => $this->softDeleted,
            'operation' => $this->operation,
        ];
    }

    /**
     * @param  array{role: Model, actor: ModelIdentifier|null, heldGrants: list<HeldGrant>, heldRoles: list<HeldRole>, softDeleted?: bool, operation?: string|null}  $values
     */
    public function __unserialize(array $values): void
    {
        $this->role = $values['role'];
        $this->actor = $this->restoreActor($values['actor']);
        $this->heldGrants = $values['heldGrants'];
        $this->heldRoles = $values['heldRoles'];
        $this->softDeleted = $values['softDeleted'] ?? false;
        $this->operation = $values['operation'] ?? null;
    }
}
