<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use ElPandaPe\Warden\Events\Concerns\CarriesActorByIdentifier;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a permission row was deleted.
 *
 * The row is gone before any queued listener runs, so it travels by value,
 * without its relations; the actor travels as an identifier.
 */
final readonly class PermissionDeleted
{
    use CarriesActorByIdentifier;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $permission,
        public ?Model $actor = null,
        public ?string $operation = null,
    ) {}

    /**
     * @return array{permission: Model, actor: ModelIdentifier|null, operation: string|null}
     */
    public function __serialize(): array
    {
        return [
            'permission' => $this->permission->withoutRelations(),
            'actor' => $this->actorIdentifier($this->actor),
            'operation' => $this->operation,
        ];
    }

    /**
     * @param  array{permission: Model, actor: ModelIdentifier|null, operation?: string|null}  $values
     */
    public function __unserialize(array $values): void
    {
        $this->permission = $values['permission'];
        $this->actor = $this->restoreActor($values['actor']);
        $this->operation = $values['operation'] ?? null;
    }
}
