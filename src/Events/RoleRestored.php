<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a role row came back from the trash.
 */
final readonly class RoleRestored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $role,
        public ?Model $actor = null,
        public ?string $operation = null,
    ) {}
}
