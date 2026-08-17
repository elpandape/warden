<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Models\Role;

final class KeylessRole extends Role
{
    public function getKey(): mixed
    {
        return null;
    }
}
