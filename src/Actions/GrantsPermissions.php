<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ElPandaPe\Warden\Actions\Concerns\RemovesByKey;
use ElPandaPe\Warden\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Warden\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\ForbiddingPermission;
use ElPandaPe\Warden\Events\GrantChange;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\PermissionForbidden;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GrantsPermissions
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use RemovesByKey;
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidding = false;

    protected ?DateTimeInterface $expiresAt = null;

    protected bool $expiryDeclared = false;

    /** @var list<Model> */
    private array $lastGranted = [];

    /**
     * The grant rows the last grant() created, not found: a where() that
     * refines the chain must not read them as the concession's history.
     *
     * @var list<int|string>
     */
    private array $freshGrantKeys = [];

    private ?Model $lastAuthority = null;

    private int|string|null $lastScope = null;

    private ?Builder $constraints = null;

    public function __construct(private readonly Model|string|null $authority) {}

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    public function to(string|array|Model|BackedEnum $permissions, Model|string|null $entity = null): static
    {
        if (! $this->permitsGrant($permissions, $entity, onlyOwned: false)) {
            return $this->forgetChain();
        }

        // The catalog row and the grant are one logical write: open the
        // boundary before the lookup so both coalesce into a single bump.
        $this->asOneWrite(function () use ($permissions, $entity): void {
            $this->grant($this->findOrCreatePermissions($permissions, $entity));
        });

        return $this;
    }

    /**
     * End the grant at a moment: past it, it stops authorizing. Call before
     * to() — writes are immediate. Pass null to lift an end date a previous
     * write left; not calling until() at all leaves that date alone, because
     * a verb that says nothing about time should not silently make a grant
     * permanent.
     */
    public function until(?DateTimeInterface $moment): static
    {
        if ($this->lastGranted !== []) {
            throw new ConfigurationException('Call until() before to(): grants execute immediately.');
        }

        $this->expiresAt = $moment;
        $this->expiryDeclared = true;

        return $this;
    }

    public function everything(): static
    {
        return $this->to('*', '*');
    }

    public function toManage(Model|string $entity): static
    {
        return $this->to('*', $entity);
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    public function toOwn(Model|string $entity, string|array|BackedEnum $permissions = '*'): static
    {
        if (! $this->permitsGrant($permissions, $entity, onlyOwned: true)) {
            return $this->forgetChain();
        }

        // An owned-only grant against a class with no ownership resolver can
        // never grant anything, and the row it writes looks like a healthy one.
        if (! Context::resolve()->resolvesOwnershipFor($entity)) {
            $class = $entity instanceof Model ? $entity::class : $entity;

            Log::warning("Warden: toOwn() wrote a grant for [{$class}], which resolves no ownership. It can never grant.");
        }

        $this->asOneWrite(function () use ($permissions, $entity): void {
            $this->grant($this->findOrCreatePermissions($permissions, $entity, onlyOwned: true));
        });

        return $this;
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    public function toOwnEverything(string|array|BackedEnum $permissions = '*'): static
    {
        return $this->toOwn('*', $permissions);
    }

    /**
     * Constrain the permissions just granted: they only authorize entities
     * matching these conditions. SQL-style precedence (AND binds tighter
     * than OR); nest a closure to group explicitly.
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        func_num_args() <= 2
            ? $this->builder()->where($column, $operator)
            : $this->builder()->where($column, $operator, $value);

        return $this->reconstrain();
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        func_num_args() <= 2
            ? $this->builder()->orWhere($column, $operator)
            : $this->builder()->orWhere($column, $operator, $value);

        return $this->reconstrain();
    }

    /**
     * Compare an entity attribute against one of the authority being checked.
     */
    public function whereColumn(string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn = null): static
    {
        $this->builder()->whereColumn($column, $operatorOrAuthorityColumn, $authorityColumn);

        return $this->reconstrain();
    }

    public function orWhereColumn(string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn = null): static
    {
        $this->builder()->orWhereColumn($column, $operatorOrAuthorityColumn, $authorityColumn);

        return $this->reconstrain();
    }

    /**
     * @param  list<Model>  $permissions
     */
    protected function grant(array $permissions): void
    {
        $this->asOneWrite(function () use ($permissions): void {
            $context = Context::resolve();
            $grantClass = $context->grantClass();
            $authority = $this->authority === null
                ? null
                : $this->resolveAuthority($this->authority, createRole: true);

            // Writes target one exact scope; role grants may stay global by configuration.
            // The unscoped lookup keeps a same-named row in another scope from absorbing it.
            $scope = app(Tenancy::class)->writeScope(
                forRoleGrant: $authority instanceof ($context->roleClass()),
            );

            $fresh = [];
            $entries = [];

            // A grant model that will not mass assign the date would drop it
            // silently, or throw: grantChange() then writes it on its own.
            $insertsExpiry = $this->expiryDeclared && (new $grantClass)->isFillable('expires_at');

            foreach ($permissions as $permission) {
                // firstOrCreate self-heals concurrent races via createOrFirst on Laravel 12+.
                $grant = $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                    'permission_id' => $this->modelKey($permission),
                    'entity_type' => $authority?->getMorphClass(),
                    'entity_id' => $authority?->getKey(),
                    'forbidden' => $this->forbidding,
                    'scope' => $scope,
                ], $insertsExpiry ? ['expires_at' => $this->expiresAt] : []);

                if ($grant->wasRecentlyCreated) {
                    $fresh[] = $this->modelKey($grant);
                }

                $entry = $this->grantChange($grant, $permission, $this->expiryDeclared, $this->expiresAt);

                if ($entry instanceof GrantChange) {
                    $entries[] = $entry;
                }
            }

            // Remembered whole, written now or already there, so a fluent
            // where() refines the concession asked for; a fresh to() starts a
            // fresh constraint set.
            $this->lastGranted = $permissions;
            $this->freshGrantKeys = $fresh;
            $this->lastAuthority = $authority;
            $this->lastScope = $scope;
            $this->constraints = null;

            // A write that wrote nothing announces nothing, as removals already
            // do. The chain state above still moves, so where() can refine it.
            if ($entries === []) {
                return;
            }

            $this->bumpCacheVersion($scope);

            $written = new Collection(array_map(fn (GrantChange $entry): Model => $entry->permission, $entries));

            $this->dispatchWardenEvent($this->forbidding
                ? new PermissionForbidden($authority, $written, $scope, actor: $this->actor(), grants: $entries)
                : new PermissionGranted($authority, $written, $scope, actor: $this->actor(), grants: $entries));
        });
    }

    private function grantChange(Model $grant, Model $permission, bool $dated, ?DateTimeInterface $expiresAt): ?GrantChange
    {
        if ($grant->wasRecentlyCreated) {
            if ($dated) {
                Expiry::apply($grant, $expiresAt);
            }

            return new GrantChange(permission: $permission, created: true, expiresAt: Expiry::of($grant), previousExpiresAt: null);
        }

        if (! $dated) {
            return null;
        }

        $previous = Expiry::of($grant);

        return Expiry::apply($grant, $expiresAt)
            ? new GrantChange(permission: $permission, created: false, expiresAt: Expiry::of($grant), previousExpiresAt: $previous)
            : null;
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    private function permitsGrant(string|array|Model|BackedEnum $permissions, Model|string|null $entity, bool $onlyOwned): bool
    {
        // First, so a refused grant dispatches nothing and leaves no catalog row.
        if ($this->authority instanceof Model) {
            $this->assertSavedAuthority($this->authority);
        }

        // The pre-event announces the scope the write will actually target —
        // computable without side effects: a string authority names a role.
        $roleAuthority = is_string($this->authority)
            || $this->authority instanceof (Context::resolve()->roleClass());

        $scope = app(Tenancy::class)->writeScope(forRoleGrant: $roleAuthority);
        $names = $this->permissionNames($permissions);

        return $this->eventPermits($this->forbidding
            ? new ForbiddingPermission($this->authority, $names, $entity, $scope, $onlyOwned)
            : new GrantingPermission($this->authority, $names, $entity, $scope, $onlyOwned));
    }

    private function builder(): Builder
    {
        return $this->constraints ??= new Builder;
    }

    /**
     * A call that wrote nothing leaves nothing to refine.
     *
     * Without this a vetoed to() keeps the previous concession armed, and the
     * where() that follows narrows a permission the chain never named.
     */
    private function forgetChain(): static
    {
        $this->lastGranted = [];
        $this->freshGrantKeys = [];
        $this->lastAuthority = null;
        $this->lastScope = null;
        $this->constraints = null;

        return $this;
    }

    /**
     * Distinct constraints mean a distinct catalog row: the grant is
     * re-pointed to a twin permission carrying the serialized group, so a
     * shared unconstrained row is never mutated under other holders.
     */
    private function reconstrain(): static
    {
        if ($this->lastGranted === []) {
            throw new ConfigurationException('Constraints need a grant to refine: call to() or toOwn() first.');
        }

        // A row with no entity is only ever a candidate for an instance-less
        // check, and a condition can never be evaluated without an instance:
        // the shape that makes it match is the shape that rejects it.
        foreach ($this->lastGranted as $permission) {
            if ($permission->getAttribute('entity_type') === null && ! (bool) $permission->getAttribute('only_owned')) {
                throw new ConfigurationException(
                    'Constraints need an entity to test: give the permission one, or drop the condition.',
                );
            }
        }

        $group = $this->builder()->group();

        // Nothing to narrow by: leave the concession as the plain one it is.
        if ($group->isEmpty()) {
            return $this;
        }

        $this->rejectUnsatisfiable($group);

        $options = ConstraintSerializer::serialize($group);

        $this->asOneWrite(function () use ($options): void {
            $grantClass = Context::resolve()->grantClass();

            // Catalog writes stay outside the grant transaction, so a listener
            // of the twin's creation can no longer roll back the re-point.
            $targets = [];
            $twins = [];

            foreach ($this->lastGranted as $base) {
                $siblings = $this->siblingsOf($base);
                $twin = $this->twinWithOptions($base, $options, $siblings);

                // The chain was handed the twin itself: its condition, restated, edits nothing.
                if ($twin->is($base)) {
                    continue;
                }

                $siblings[$this->modelKey($twin)] = $twin;
                $twins[$this->modelKey($base)] = $twin;
                $targets[] = [$base, $twin, $siblings];
            }

            [$removals, $changes] = $this->repoint($targets);

            $this->lastGranted = array_map(fn (Model $permission): Model => $twins[$this->modelKey($permission)] ?? $permission, $this->lastGranted);

            $this->announceRepoint($removals, $changes);

            // A base row this chain just created, now orphaned, goes away.
            foreach ($targets as [$base]) {
                $orphaned = $base->wasRecentlyCreated
                    && ! $grantClass::query()->withoutGlobalScope(TenantScope::class)
                        ->where('permission_id', $this->modelKey($base))
                        ->exists();

                if ($orphaned) {
                    $base->delete();
                }
            }
        });

        return $this;
    }

    /**
     * Deleting a grant and re-pointing it to the twin is one edit: a failure
     * between the two would leave the concession simply gone. Every sibling's
     * grant goes, not just the base's: a second where() edits the rule, and
     * leaving the previous twin's grant alive would authorise a union.
     *
     * @param  list<array{Model, Model, array<int|string, Model>}>  $targets
     * @return array{list<GrantRemoval>, list<GrantChange>}
     */
    private function repoint(array $targets): array
    {
        $grantClass = Context::resolve()->grantClass();
        $keyName = (new $grantClass)->getKeyName();

        // As in grant(): a model that will not mass assign the date gets it from grantChange().
        $insertsExpiry = (new $grantClass)->isFillable('expires_at');

        return (new $grantClass)->getConnection()->transaction(function () use ($targets, $grantClass, $keyName, $insertsExpiry): array {
            $removals = [];
            $changes = [];

            foreach ($targets as [, $twin, $siblings]) {
                $held = $grantClass::query()->withoutGlobalScope(TenantScope::class)
                    ->whereIn('permission_id', array_keys($siblings))
                    ->where('entity_type', $this->lastAuthority?->getMorphClass())
                    ->where('entity_id', $this->lastAuthority?->getKey())
                    ->where('forbidden', $this->forbidding)
                    ->where('scope', $this->lastScope)
                    ->orderBy($keyName)
                    ->get([$keyName, 'permission_id', 'expires_at']);

                $expiresAt = $this->carriedExpiry($held);
                $twinKey = $this->modelKey($twin);
                $superseded = [];

                foreach ($held as $row) {
                    if ((string) $row->permission_id !== (string) $twinKey) {
                        $superseded[] = $row;
                    }
                }

                foreach ($this->deleteByKey($superseded) as $row) {
                    $removals[] = new GrantRemoval(permission: $siblings[$row->permission_id], expiresAt: Expiry::of($row));
                }

                // The twin's row is kept, never deleted and recreated: it only takes the carried date.
                $grant = $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                    'permission_id' => $twinKey,
                    'entity_type' => $this->lastAuthority?->getMorphClass(),
                    'entity_id' => $this->lastAuthority?->getKey(),
                    'forbidden' => $this->forbidding,
                    'scope' => $this->lastScope,
                ], $insertsExpiry ? ['expires_at' => $expiresAt] : []);

                $change = $this->grantChange($grant, $twin, dated: true, expiresAt: $expiresAt);

                if ($change instanceof GrantChange) {
                    $changes[] = $change;
                }
            }

            return [$removals, $changes];
        });
    }

    /**
     * @param  list<GrantRemoval>  $removals
     * @param  list<GrantChange>  $changes
     */
    private function announceRepoint(array $removals, array $changes): void
    {
        if ($removals === [] && $changes === []) {
            return;
        }

        $actor = $this->actor();

        if ($removals !== []) {
            // A delete by key fires no model hook; the twin's own save marks itself.
            $this->bumpCacheVersion($this->lastScope);

            $permissions = new Collection(array_map(static fn (GrantRemoval $removal): Model => $removal->permission, $removals));

            $this->dispatchWardenEvent($this->forbidding
                ? new PermissionUnforbidden($this->lastAuthority, $permissions, $this->lastScope, actor: $actor, grants: $removals)
                : new PermissionRevoked($this->lastAuthority, $permissions, $this->lastScope, actor: $actor, grants: $removals));
        }

        if ($changes !== []) {
            $permissions = new Collection(array_map(static fn (GrantChange $change): Model => $change->permission, $changes));

            $this->dispatchWardenEvent($this->forbidding
                ? new PermissionForbidden($this->lastAuthority, $permissions, $this->lastScope, actor: $actor, grants: $changes)
                : new PermissionGranted($this->lastAuthority, $permissions, $this->lastScope, actor: $actor, grants: $changes));
        }
    }

    /**
     * A declared until() decides, null included. Otherwise the rows that stood
     * before this chain do, never the one its own to() just created. When they
     * disagree the later end wins and no end beats any, as CachedResolver reads
     * two live rows. Decided here, not in SQL: no engine promises a row order.
     *
     * @param  iterable<Model>  $superseded
     */
    private function carriedExpiry(iterable $superseded): ?DateTimeInterface
    {
        if ($this->expiryDeclared) {
            return $this->expiresAt;
        }

        $ends = [];

        foreach ($superseded as $row) {
            if (in_array($this->modelKey($row), $this->freshGrantKeys, true)) {
                continue;
            }

            /** @var DateTimeInterface|string|null $end */
            $end = $row->getAttribute('expires_at');

            if ($end === null) {
                return null;
            }

            $ends[] = $end instanceof DateTimeInterface ? $end : Carbon::parse($end);
        }

        return $ends === [] ? null : max($ends);
    }

    /**
     * A boolean compares only against a column the model casts to bool, and
     * such a column only against a boolean: either mismatch can never be true.
     * Written on a forbid it would leave the grant beneath it live, and
     * explain() would report that grant without mentioning the prohibition —
     * so the write is refused where there is still a person to tell.
     */
    private function rejectUnsatisfiable(Group $group): void
    {
        foreach ($this->lastGranted as $permission) {
            $type = $permission->getAttribute('entity_type');

            if (! is_string($type) || $type === '*') {
                continue;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                continue; // @codeCoverageIgnore
            }

            foreach ($group->unsatisfiableColumns(new $class) as $column) {
                throw new ConfigurationException(
                    "The condition on [{$column}] can never be true for [{$class}]: a boolean matches only "
                    .'a column the model casts to bool. Add the cast, or compare against a column that has it.',
                );
            }
        }
    }

    /**
     * Every catalog row of this permission's shape, conditions aside, by key.
     *
     * @return array<int|string, Model>
     */
    private function siblingsOf(Model $permission): array
    {
        $rows = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('name', $permission->getAttribute('name'))
            ->where('entity_type', $permission->getAttribute('entity_type'))
            ->where('entity_id', $permission->getAttribute('entity_id'))
            ->where('only_owned', $permission->getAttribute('only_owned'))
            ->where('scope', $permission->getAttribute('scope'))
            ->get();

        $siblings = [];

        foreach ($rows as $sibling) {
            $siblings[$this->modelKey($sibling)] = $sibling;
        }

        return $siblings;
    }

    /**
     * @param  array{v: int, g: array<string, mixed>}  $options
     * @param  array<int|string, Model>  $siblings
     */
    private function twinWithOptions(Model $base, array $options, array $siblings): Model
    {
        // Options compare in PHP: JSON equality is not portable across engines.
        foreach ($siblings as $sibling) {
            if (ConstraintSerializer::sameRule($sibling->getAttribute('options'), $options)) {
                return $sibling;
            }
        }

        return Context::resolve()->permissionClass()::query()->create([
            'name' => $base->getAttribute('name'),
            'entity_type' => $base->getAttribute('entity_type'),
            'entity_id' => $base->getAttribute('entity_id'),
            'only_owned' => $base->getAttribute('only_owned'),
            'options' => $options,
            'scope' => $base->getAttribute('scope'),
        ]);
    }
}
