<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a permission row was deleted.
 */
final readonly class PermissionDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Model $permission) {}
}
