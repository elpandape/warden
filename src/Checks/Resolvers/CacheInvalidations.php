<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Resolvers;

use ElPandaPe\Warden\Context;

/**
 * Decides when the cache counter moves. CacheKeyVersioner is the only thing
 * that moves it; every write path announces through here.
 */
final readonly class CacheInvalidations
{
    public function __construct(private CacheKeyVersioner $versioner) {}

    /**
     * A write happened at this scope.
     *
     * Inside a database transaction the bump runs twice: immediately, so this
     * request's own checks see the write, and again after commit, so a payload
     * rebuilt by a concurrent reader from pre-commit rows gets orphaned too.
     */
    public function mark(int|string|null $scope): void
    {
        $this->versioner->bump($scope);

        $connection = (new (Context::resolve()->grantClass()))->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(function () use ($scope): void {
                $this->versioner->bump($scope);
            });
        }
    }
}
