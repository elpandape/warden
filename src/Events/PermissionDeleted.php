<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a permission row was deleted.
 *
 * The row is gone before any queued listener runs, so the event travels by
 * value instead of as an identifier to read back. SerializesModels stays:
 * dropping it would take its public methods with it.
 */
final readonly class PermissionDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $permission,
        public ?Model $actor = null,
    ) {}

    /**
     * @return array{permission: Model, actor: Model|null}
     */
    public function __serialize(): array
    {
        return [
            'permission' => $this->permission,
            'actor' => $this->actor,
        ];
    }

    /**
     * @param  array{permission: Model, actor: Model|null}  $values
     */
    public function __unserialize(array $values): void
    {
        $this->permission = $values['permission'];
        $this->actor = $values['actor'];
    }
}
