<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a role row was deleted.
 */
final readonly class RoleDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Model $role) {}
}
