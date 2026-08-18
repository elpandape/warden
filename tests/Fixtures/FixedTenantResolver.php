<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Contracts\TenantResolver;

final class FixedTenantResolver implements TenantResolver
{
    public function resolve(): int
    {
        return 42;
    }
}
