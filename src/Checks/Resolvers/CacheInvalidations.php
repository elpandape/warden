<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Resolvers;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * What happens when rows change outside the fluent actions: the counter moves,
 * and the writes nobody performed get announced. CacheKeyVersioner is still the
 * only thing that touches the counter.
 */
final class CacheInvalidations
{
    private int $depth = 0;

    /** @var array<string, int|string|null> */
    private array $pending = [];

    /** @var array<int, list<int|string|null>> */
    private array $cascading = [];

    /** @var array<int, list<array{string|null, int|string|null, bool, int|string|null}>> */
    private array $doomed = [];

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

        if ($model::class === $context->permissionClass()) {
            $this->doomed[spl_object_id($model)] = $this->grantsPointingAt($key);
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
     * The engine has cascaded: mark the scopes it reached and sweep what no
     * foreign key covers. The catalog model calls this from its own deleted
     * hook, ahead of every listener of its event, so a listener that throws
     * cannot leave the cache granting what the delete removed.
     */
    public function settleCascade(Model $model): void
    {
        $object = spl_object_id($model);
        $scopes = $this->cascading[$object] ?? [];
        unset($this->cascading[$object]);

        $context = Context::resolve();

        // Object ids are reused: an entry left by a delete that never finished
        // can meet a later model that happens to receive the same id.
        if (! in_array($model::class, [$context->permissionClass(), $context->roleClass()], true)) {
            return;
        }

        foreach ($scopes as $scope) {
            $this->mark($scope);
        }

        $this->sweepStrandedGrants($model);
    }

    /**
     * The cascade removed grants nobody asked to remove: say so, with the
     * payload shape the write paths already publish. Called after the catalog
     * event, so a listener of that event that throws loses these announcements
     * and nothing else.
     */
    public function announceCascade(Model $model): void
    {
        $object = spl_object_id($model);
        $grants = $this->doomed[$object] ?? [];
        unset($this->doomed[$object]);

        if ($model::class !== Context::resolve()->permissionClass() || $grants === [] || ! Config::eventsEnabled()) {
            return;
        }

        $actor = app(ActorResolver::class)->resolve();
        $permissions = new Collection([$model]);

        foreach ($grants as [$type, $key, $forbidden, $scope]) {
            $everyone = $type === null && $key === null;
            $authority = $everyone ? null : $this->hydrate($type, $key);

            // Only a row with neither holder column reached everyone. Any other
            // row whose holder cannot be named authorized nobody, and a null
            // authority would announce it as a grant to everyone.
            if (! $everyone && ! $authority instanceof Model) {
                continue;
            }

            Event::dispatch($forbidden
                ? new PermissionUnforbidden($authority, $permissions, $scope, $actor)
                : new PermissionRevoked($authority, $permissions, $scope, $actor));
        }
    }

    /**
     * The whole cascade in one call, as 3.0.0 ran it. The catalog models no
     * longer call it: their own event goes out between the two halves.
     *
     * @deprecated 3.0.1 Call settleCascade(), then announceCascade().
     */
    public function markCascade(Model $model): void
    {
        $this->settleCascade($model);
        $this->announceCascade($model);
    }

    /**
     * @return list<array{string|null, int|string|null, bool, int|string|null}>
     */
    private function grantsPointingAt(int|string $permissionKey): array
    {
        $rows = Context::resolve()->grantClass()::query()
            ->withoutGlobalScopes()
            ->getQuery()
            ->where('permission_id', $permissionKey)
            ->get(['entity_type', 'entity_id', 'forbidden', 'scope']);

        /** @var list<array{string|null, int|string|null, bool, int|string|null}> $grants */
        $grants = $rows->map(fn (object $row): array => [
            is_string($row->entity_type) ? $row->entity_type : null,
            is_int($row->entity_id) || is_string($row->entity_id) ? $row->entity_id : null,
            (bool) $row->forbidden,
            is_int($row->scope) || is_string($row->scope) ? $row->scope : null,
        ])->values()->all();

        return $grants;
    }

    private function hydrate(?string $type, int|string|null $key): ?Model
    {
        if ($type === null || $key === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class)) {
            Log::warning("Warden: no model class maps the morph type [{$type}], so its rows cannot be named.", ['ids' => [$key]]);

            return null;
        }

        // Unscoped: naming a holder is not an authorization read, and a tenant
        // or soft-delete filter would hide one the cascade still reached.
        return $class::query()->withoutGlobalScopes()->find($key);
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
            // The catalog too: build() bakes a permission's own columns into
            // the cached tuple, so editing one by the model must invalidate.
            $context->permissionClass(),
        ], true);
    }
}
