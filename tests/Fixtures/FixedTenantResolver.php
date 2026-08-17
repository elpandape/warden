<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Contracts\TenantResolver;

final class FixedTenantResolver implements TenantResolver
{
    public function resolve(): int
    {
        return 42;
    }
}
