<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Role;

final class KeylessRole extends Role
{
    public function getKey(): mixed
    {
        return null;
    }
}
