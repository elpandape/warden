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

    /**
     * One logical write: the action's own bump and the model hooks its rows
     * fire describe the same thing, and coalesce to one.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function asOneWrite(callable $write): mixed
    {
        return app(CacheInvalidations::class)->during($write);
    }
}
