<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a permission row came back from the trash.
 */
final readonly class PermissionRestored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $permission,
        public ?Model $actor = null,
        public ?string $operation = null,
    ) {}
}
