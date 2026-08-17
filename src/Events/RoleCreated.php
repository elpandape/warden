<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catalog lifecycle: a role row was created (on-the-fly creation included).
 */
final readonly class RoleCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Model $role) {}
}
