<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Contracts\TenantResolver;

final class CountingTenantResolver implements TenantResolver
{
    public static int $calls = 0;

    public function resolve(): int
    {
        self::$calls++;

        return 7;
    }
}
