<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;

trait BumpsCacheVersion
{
    private function bumpCacheVersion(int|string|null $scope): void
    {
        app(CacheInvalidations::class)->mark($scope);
    }
}
