<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Context;

trait BumpsCacheVersion
{
    /**
     * Every write invalidates cached checks for the exact scope it targeted.
     *
     * Inside a database transaction the bump runs twice: immediately, so this
     * request's own checks see the write, and again after commit, so a payload
     * rebuilt by a concurrent reader from pre-commit rows gets orphaned too.
     */
    private function bumpCacheVersion(int|string|null $scope): void
    {
        app(CacheKeyVersioner::class)->bump($scope);

        $connection = (new (Context::resolve()->grantClass()))->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(static function () use ($scope): void {
                app(CacheKeyVersioner::class)->bump($scope);
            });
        }
    }
}
