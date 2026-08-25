<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Resolvers;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides when the cache counter moves. CacheKeyVersioner is the only thing
 * that moves it; every write path announces through here.
 */
final class CacheInvalidations
{
    private int $depth = 0;

    /** @var array<string, int|string|null> */
    private array $pending = [];

    public function __construct(private readonly CacheKeyVersioner $versioner) {}

    /**
     * One logical write, however many rows it touches.
     *
     * An action and the model hooks beneath it describe the same write, and a
     * batch describes one: inside this boundary they coalesce to a single bump
     * per scope. Without a boundary a mark bumps immediately, so any path that
     * does not declare itself keeps today's behaviour.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function during(callable $operation): mixed
    {
        $this->depth++;

        try {
            return $operation();
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $pending = $this->pending;
                $this->pending = [];

                foreach ($pending as $scope) {
                    $this->bump($scope);
                }
            }
        }
    }

    /**
     * A write happened at this scope.
     */
    public function mark(int|string|null $scope): void
    {
        if ($this->depth > 0) {
            $this->pending[$scope === null ? 'null' : get_debug_type($scope).':'.$scope] = $scope;

            return;
        }

        $this->bump($scope);
    }

    /**
     * A row warden owns changed outside the fluent actions — a model write, or
     * a relation attach/detach, which saves and deletes the pivot one row at a
     * time.
     */
    public function markFrom(Model $model): void
    {
        if (! $this->isWardenRow($model)) {
            return;
        }

        $scope = $model->getAttribute('scope');

        $this->mark(is_int($scope) || is_string($scope) ? $scope : null);
    }

    /**
     * Inside a database transaction the bump runs twice: immediately, so this
     * request's own checks see the write, and again after commit, so a payload
     * rebuilt by a concurrent reader from pre-commit rows gets orphaned too.
     */
    private function bump(int|string|null $scope): void
    {
        $this->versioner->bump($scope);

        $connection = (new (Context::resolve()->grantClass()))->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(function () use ($scope): void {
                $this->versioner->bump($scope);
            });
        }
    }

    private function isWardenRow(Model $model): bool
    {
        $context = Context::resolve();

        return in_array($model::class, [
            $context->grantClass(),
            $context->assignedRoleClass(),
        ], true);
    }
}
