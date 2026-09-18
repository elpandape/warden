<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Resolvers;

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Support\MorphHydrator;
use ElPandaPe\Warden\Support\Operations;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What happens when rows change outside the fluent actions: the counter moves,
 * and the writes nobody performed get announced. CacheKeyVersioner is still the
 * only thing that touches the counter.
 *
 * @phpstan-import-type HeldGrant from RoleDeleted
 * @phpstan-import-type HeldRole from RoleDeleted
 *
 * @phpstan-type Holder array{string, int|string, int|string|null, string|null, int|string|null, CarbonImmutable|null}
 */
final class CacheInvalidations
{
    private int $depth = 0;

    /** @var array<string, int|string|null> */
    private array $pending = [];

    /** @var array<int, list<int|string|null>> */
    private array $cascading = [];

    /** @var array<int, list<array{string|null, int|string|null, bool, int|string|null, CarbonImmutable|null}>> */
    private array $doomed = [];

    /** @var array<int, list<Holder>> */
    private array $holders = [];

    /** @var array<int, array{grants: list<HeldGrant>, roles: list<HeldRole>}> */
    private array $held = [];

    public function __construct(private readonly CacheKeyVersioner $versioner) {}

    /**
     * One logical write, however many rows it touches.
     *
     * An action and the model hooks beneath it describe the same write, and a
     * batch describes one: inside this boundary their marks coalesce per scope
     * until it closes, or until an announcement applies them first. Without a
     * boundary a mark bumps immediately, so any path that does not declare
     * itself keeps today's behaviour.
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
                $this->flush();
            }
        }
    }

    /**
     * Apply the bumps a boundary is holding without closing it: whoever hears
     * about a write next must not be answered from before it.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $scope) {
            $this->bump($scope);
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

        // As stored, not through the accessor: a row read without its scope
        // must not throw in strict mode once its write has landed. Unread, the
        // scope counts as global, and a global mark reaches every cached key.
        $scopes = [$model->getAttributes()['scope'] ?? null];

        // A row moved out of a scope still sits in that scope's cached payloads.
        // Eloquent syncs the original only after the updated event, so it still
        // holds the scope the row left.
        if ($model->wasChanged('scope')) {
            $scopes[] = $model->getRawOriginal('scope');
        }

        foreach ($scopes as $scope) {
            $this->mark(is_int($scope) || is_string($scope) ? $scope : null);
        }
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
        $id = spl_object_id($model);

        // PHP reuses object ids: what a vetoed delete read must not reach this one.
        unset($this->cascading[$id], $this->doomed[$id], $this->holders[$id], $this->held[$id]);

        if (! is_int($key) && ! is_string($key)) {
            return; // @codeCoverageIgnore
        }

        if ($model::class === $context->permissionClass()) {
            // A soft delete cascades nothing, yet the trashed permission stops
            // granting at once: the scopes its grants live in move all the same.
            $this->cascading[$id] = $this->scopesOf($context->grantClass(), 'permission_id', $key);

            // Only a hard delete cascades, and its grants are read only to be announced.
            if (! $this->softDeleting($model) && Config::eventsEnabled()) {
                $this->doomed[$id] = $this->grantsPointingAt($key);
            }

            return;
        }

        if ($model::class !== $context->roleClass() || $this->softDeleting($model)) {
            return;
        }

        $morph = $model->getMorphClass();

        $this->cascading[$id] = [
            ...$this->scopesOf($context->assignedRoleClass(), 'role_id', $key),
            ...$this->scopesOf($context->grantClass(), 'entity_id', $key, $morph),
            ...$this->scopesOf($context->assignedRoleClass(), 'entity_id', $key, $morph),
        ];

        // The rest is read only to be announced.
        if (! Config::eventsEnabled()) {
            return;
        }

        $this->holders[$id] = $this->roleHoldersOf($key);
        $this->held[$id] = [
            'grants' => $this->grantsHeldBy($morph, $key),
            'roles' => $this->rolesHeldBy($morph, $key),
        ];
    }

    /**
     * The delete has landed: mark the scopes prepareCascade() read and sweep
     * what no foreign key covers. The catalog model calls this from its own
     * deleted hook, ahead of every listener of its event, so a listener that
     * throws cannot leave the cache granting what the delete removed.
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

        $this->sweepHoldings($model);
    }

    /**
     * What a role being deleted held, as prepareCascade() read it. Handed over
     * once: the entry goes with it.
     *
     * @return array{grants: list<HeldGrant>, roles: list<HeldRole>}
     */
    public function pullHeld(Model $role): array
    {
        $id = spl_object_id($role);
        $held = $this->held[$id] ?? ['grants' => [], 'roles' => []];
        unset($this->held[$id]);

        return $held;
    }

    /**
     * The cascade removed rows nobody asked to remove — a role's assignments,
     * a permission's grants: say so, with the payload shape the write paths
     * already publish. Called after the catalog event, so a listener of that
     * event that throws loses these announcements and nothing else.
     */
    public function announceCascade(Model $model): void
    {
        $object = spl_object_id($model);
        $roleHolders = $this->holders[$object] ?? [];
        unset($this->holders[$object]);

        if ($roleHolders !== [] && $model::class === Context::resolve()->roleClass()) {
            $this->announceRetractions($model, $roleHolders);
        }

        $grants = $this->doomed[$object] ?? [];
        unset($this->doomed[$object]);

        if ($model::class !== Context::resolve()->permissionClass() || $grants === []) {
            return;
        }

        $actor = Announcer::actorOnce();
        $permissions = new Collection([$model]);
        $authorities = $this->authoritiesOf($grants);

        foreach ($grants as [$type, $key, $forbidden, $scope, $expiresAt]) {
            $everyone = $type === null && $key === null;
            $authority = $type === null || $key === null ? null : $authorities[MorphHydrator::key($type, $key)] ?? null;

            // Only a row with neither holder column reached everyone. Any other
            // row whose holder cannot be named authorized nobody, and a null
            // authority would announce it as a grant to everyone.
            if (! $everyone && ! $authority instanceof Model) {
                continue;
            }

            $removed = [new GrantRemoval(permission: $model, expiresAt: $expiresAt)];

            Announcer::announce(fn (): PermissionUnforbidden|PermissionRevoked => $forbidden
                ? new PermissionUnforbidden($authority, $permissions, $scope, actor: $actor(), grants: $removed, operation: app(Operations::class)->current())
                : new PermissionRevoked($authority, $permissions, $scope, actor: $actor(), grants: $removed, operation: app(Operations::class)->current()));
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
        unset($this->held[spl_object_id($model)]);
    }

    /**
     * @return list<array{string|null, int|string|null, bool, int|string|null, CarbonImmutable|null}>
     */
    private function grantsPointingAt(int|string $permissionKey): array
    {
        // Models, not the base builder: Expiry::of() reads the end date the way
        // the write paths do.
        $rows = $this->pivotRows(Context::resolve()->grantClass(), 'permission_id', $permissionKey, null, [
            'entity_type', 'entity_id', 'forbidden', 'scope', 'expires_at',
        ]);

        /** @var list<array{string|null, int|string|null, bool, int|string|null, CarbonImmutable|null}> $grants */
        $grants = $rows->map(function (Model $grant): array {
            $type = $grant->getAttribute('entity_type');
            $key = $grant->getAttribute('entity_id');
            $scope = $grant->getAttribute('scope');

            return [
                is_string($type) ? $type : null,
                is_int($key) || is_string($key) ? $key : null,
                (bool) $grant->getAttribute('forbidden'),
                is_int($scope) || is_string($scope) ? $scope : null,
                Expiry::of($grant),
            ];
        })->values()->all();

        return $grants;
    }

    /**
     * @param  list<array{string|null, int|string|null, bool, int|string|null, CarbonImmutable|null}>  $grants
     * @return array<string, Model>
     */
    private function authoritiesOf(array $grants): array
    {
        $pairs = [];

        foreach ($grants as [$type, $key]) {
            if ($type !== null && $key !== null) {
                $pairs[] = [$type, $key];
            }
        }

        return MorphHydrator::many($pairs);
    }

    /**
     * Every assignment of the role, expired ones included: the foreign key
     * takes them all, and each is announced with the date it carried.
     *
     * @return list<Holder>
     */
    private function roleHoldersOf(int|string $roleKey): array
    {
        $rows = $this->pivotRows(Context::resolve()->assignedRoleClass(), 'role_id', $roleKey, null, [
            'entity_type', 'entity_id', 'scope', 'restricted_to_type', 'restricted_to_id', 'expires_at',
        ]);

        $holders = [];

        foreach ($rows as $row) {
            $type = $row->getAttribute('entity_type');
            $holder = $row->getAttribute('entity_id');

            if (! is_string($type) || (! is_int($holder) && ! is_string($holder))) {
                continue; // @codeCoverageIgnore
            }

            $contextType = $row->getAttribute('restricted_to_type');

            $holders[] = [
                $type,
                $holder,
                $this->scalar($row->getAttribute('scope')),
                is_string($contextType) ? $contextType : null,
                $this->scalar($row->getAttribute('restricted_to_id')),
                Expiry::of($row),
            ];
        }

        return $holders;
    }

    /**
     * @return list<HeldGrant>
     */
    private function grantsHeldBy(string $morph, int|string $roleKey): array
    {
        $context = Context::resolve();
        $catalog = (new ($context->permissionClass()))->getMorphClass();
        $held = [];

        foreach ($this->heldRows($context->grantClass(), 'permission_id', $catalog, $morph, $roleKey, ['forbidden', 'scope', 'expires_at']) as [$permission, $row]) {
            $held[] = [
                'permission' => PermissionSnapshot::of($permission),
                'forbidden' => (bool) $row->getAttribute('forbidden'),
                'scope' => $this->scalar($row->getAttribute('scope')),
                'expires_at' => Expiry::of($row),
            ];
        }

        return $held;
    }

    /**
     * @return list<HeldRole>
     */
    private function rolesHeldBy(string $morph, int|string $roleKey): array
    {
        $held = [];

        foreach ($this->heldRows(Context::resolve()->assignedRoleClass(), 'role_id', $morph, $morph, $roleKey, ['scope', 'restricted_to_type', 'restricted_to_id', 'expires_at']) as [$role, $row]) {
            $contextType = $row->getAttribute('restricted_to_type');

            $held[] = [
                'role' => RoleSnapshot::of($role),
                'scope' => $this->scalar($row->getAttribute('scope')),
                'restricted_to_type' => is_string($contextType) ? $contextType : null,
                'restricted_to_id' => $this->scalar($row->getAttribute('restricted_to_id')),
                'expires_at' => Expiry::of($row),
            ];
        }

        return $held;
    }

    /**
     * The rows a role holds on one pivot, each beside the catalog row it points
     * at, read before the sweep takes them.
     *
     * @param  class-string<Model>  $pivot
     * @param  list<string>  $columns
     * @return list<array{Model, Model}>
     */
    private function heldRows(string $pivot, string $pointer, string $catalog, string $morph, int|string $roleKey, array $columns): array
    {
        $rows = $this->pivotRows($pivot, 'entity_id', $roleKey, $morph, [$pointer, ...$columns]);
        $pairs = [];

        foreach ($rows as $row) {
            $key = $this->scalar($row->getAttribute($pointer));

            if ($key !== null) {
                $pairs[] = [$catalog, $key];
            }
        }

        $models = MorphHydrator::many($pairs);
        $held = [];

        foreach ($rows as $row) {
            $key = $this->scalar($row->getAttribute($pointer));
            $model = $key === null ? null : $models[MorphHydrator::key($catalog, $key)] ?? null;

            if ($model instanceof Model) {
                $held[] = [$model, $row];
            }
        }

        return $held;
    }

    /**
     * Read unscoped and in key order: the tenant filter hides rows the cascade
     * destroys anyway, and nothing may hang on the order an engine returns.
     *
     * @param  class-string<Model>  $pivot
     * @param  list<string>  $columns
     * @return EloquentCollection<int, Model>
     */
    private function pivotRows(string $pivot, string $column, int|string $key, ?string $morph, array $columns): EloquentCollection
    {
        $query = $pivot::query()->withoutGlobalScopes();
        $query->getQuery()->where($column, $key);

        if ($morph !== null) {
            $query->getQuery()->where('entity_type', $morph);
        }

        $keyName = $query->getModel()->getKeyName();

        return $query->orderBy($keyName)->get([$keyName, ...$columns]);
    }

    /**
     * The foreign key took every assignment of the role, in every tenant and
     * context: one retraction per holder and scope, each row with its own
     * context and date. RetractingRole never fires — nothing could veto a
     * cascade the engine already ran.
     *
     * @param  list<Holder>  $holders
     */
    private function announceRetractions(Model $role, array $holders): void
    {
        // It travels by value in every entry: the relations the caller loaded stay behind.
        $role = $role->withoutRelations();
        $pairs = [];

        foreach ($holders as [$type, $holder, , $contextType, $contextId]) {
            $pairs[] = [$type, $holder];

            if ($contextType !== null && $contextId !== null) {
                $pairs[] = [$contextType, $contextId];
            }
        }

        $models = MorphHydrator::many($pairs);
        $authorities = [];
        $scopes = [];
        $removals = [];

        foreach ($holders as [$type, $holder, $scope, $contextType, $contextId, $expiresAt]) {
            $authority = $models[MorphHydrator::key($type, $holder)] ?? null;

            if ($authority === null) {
                continue;
            }

            $restrictedTo = null;

            if ($contextType !== null && $contextId !== null) {
                $restrictedTo = $models[MorphHydrator::key($contextType, $contextId)]
                    ?? MorphHydrator::standIn($contextType, $contextId);

                if ($restrictedTo === null) {
                    continue;
                }
            } elseif ($contextType !== null || $contextId !== null) {
                // Half a restriction is not "unrestricted": fail closed, as RoleClosure does.
                continue;
            }

            $group = serialize([$type, $holder, $scope]);
            $authorities[$group] = $authority;
            $scopes[$group] = $scope;
            $removals[$group][] = new AssignmentRemoval($role, $restrictedTo, $expiresAt);
        }

        $actor = Announcer::actorOnce();

        foreach ($removals as $group => $assignments) {
            Announcer::announce(fn (): RoleRetracted => new RoleRetracted(
                $authorities[$group],
                new Collection([$role]),
                $scopes[$group],
                actor: $actor(),
                assignments: $assignments,
                operation: app(Operations::class)->current(),
            ));
        }
    }

    /**
     * A role holds grants, and the roles nested inside it, through polymorphic
     * columns no foreign key reaches: deleting the role would otherwise leave
     * both behind, and RoleClosure::reaching() would still climb the edges.
     */
    private function sweepHoldings(Model $model): void
    {
        $context = Context::resolve();

        if ($model::class !== $context->roleClass() || $this->softDeleting($model)) {
            return;
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return; // @codeCoverageIgnore
        }

        foreach ([$context->grantClass(), $context->assignedRoleClass()] as $pivot) {
            $pivot::query()
                ->withoutGlobalScopes()
                ->getQuery()
                ->where('entity_type', $model->getMorphClass())
                ->where('entity_id', $key)
                ->delete();
        }
    }

    /**
     * Model::delete() fires the delete events for a soft delete too, yet the
     * row stays and nothing cascades.
     */
    private function softDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting() === false;
    }

    private function scalar(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * Inside a database transaction the bump runs twice: immediately, so this
     * request's own checks see the write, and again once the transaction ends.
     * After a commit, a payload rebuilt by a concurrent reader from pre-commit
     * rows gets orphaned too; after a rollback, savepoints included, so does
     * one this request cached from rows that never landed.
     */
    private function bump(int|string|null $scope): void
    {
        $this->versioner->bump($scope);

        $connection = (new (Context::resolve()->grantClass()))->getConnection();

        if ($connection->transactionLevel() > 0) {
            $again = function () use ($scope): void {
                $this->versioner->bump($scope);
            };

            $connection->afterCommit($again);
            $connection->afterRollBack($again);
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
