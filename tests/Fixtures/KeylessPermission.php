<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Database\Permission;

final class KeylessPermission extends Permission
{
    public function getKey(): mixed
    {
        return null;
    }
}
