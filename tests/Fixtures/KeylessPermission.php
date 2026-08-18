<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Permission;

final class KeylessPermission extends Permission
{
    public function getKey(): mixed
    {
        return null;
    }
}
