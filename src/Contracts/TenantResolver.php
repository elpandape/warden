<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Contracts;

interface TenantResolver
{
    /**
     * Resolve the currently active tenant identifier, if any.
     */
    public function resolve(): int|string|null;
}
