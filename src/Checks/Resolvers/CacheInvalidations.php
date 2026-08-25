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

    /** @var array<int, list<int|string|null>> */
    private array $cascading = [];

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
     * A catalog row is about to go, and a foreign key will take its grants with
     * it — inside the engine, where no model event fires. The cascade cannot be
     * hooked, but it is declarative, so the scopes it will reach can be read
     * before the delete and announced after it.
     *
     * Read unscoped on purpose: the tenant filter hides rows the cascade
     * destroys anyway.
     */
    public function prepareCascade(Model $model): void
    {
        $context = Context::resolve();
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return; // @codeCoverageIgnore
        }

        $scopes = match ($model::class) {
            $context->permissionClass() => $this->scopesOf($context->grantClass(), 'permission_id', $key),
            $context->roleClass() => [
                ...$this->scopesOf($context->assignedRoleClass(), 'role_id', $key),
                ...$this->scopesOf($context->grantClass(), 'entity_id', $key, $model->getMorphClass()),
            ],
            default => null,
        };

        if ($scopes !== null) {
            $this->cascading[spl_object_id($model)] = $scopes;
        }
    }

    /**
     * The engine has cascaded: announce what it reached, and sweep what no
     * foreign key covers.
     */
    public function markCascade(Model $model): void
    {
        $id = spl_object_id($model);

        foreach ($this->cascading[$id] ?? [] as $scope) {
            $this->mark($scope);
        }

        unset($this->cascading[$id]);

        $this->sweepStrandedGrants($model);
    }

    /**
     * A role holds its grants through polymorphic columns, which no foreign key
     * reaches: deleting the role would otherwise leave them behind forever,
     * invisible to warden:clean.
     */
    private function sweepStrandedGrants(Model $model): void
    {
        $context = Context::resolve();

        if ($model::class !== $context->roleClass()) {
            return;
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return; // @codeCoverageIgnore
        }

        $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->getQuery()
            ->where('entity_type', $model->getMorphClass())
            ->where('entity_id', $key)
            ->delete();
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

    /**
     * @param  class-string<Model>  $class
     * @return list<int|string|null>
     */
    private function scopesOf(string $class, string $column, int|string $key, ?string $morph = null): array
    {
        // The base builder, not Eloquent: this is a maintenance read with a
        // dynamic column, not an authorization read.
        $query = $class::query()->withoutGlobalScopes()->getQuery()->where($column, $key);

        if ($morph !== null) {
            $query->where('entity_type', $morph);
        }

        $rows = $query->distinct()->pluck('scope');

        /** @var list<int|string|null> $scopes */
        $scopes = $rows->map(fn (mixed $scope): int|string|null => is_int($scope) || is_string($scope) ? $scope : null)
            ->unique()
            ->values()
            ->all();

        return $scopes;
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
