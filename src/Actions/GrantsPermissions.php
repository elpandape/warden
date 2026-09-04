<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use Closure;
use ElPandaPe\Warden\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Warden\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\ForbiddingPermission;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\PermissionForbidden;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class GrantsPermissions
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidding = false;

    /** @var list<Model> */
    private array $lastGranted = [];

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

            $wrote = false;

            foreach ($permissions as $permission) {
                // firstOrCreate self-heals concurrent races via createOrFirst on Laravel 12+.
                $grant = $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                    'permission_id' => $this->modelKey($permission),
                    'entity_type' => $authority?->getMorphClass(),
                    'entity_id' => $authority?->getKey(),
                    'forbidden' => $this->forbidding,
                    'scope' => $scope,
                ]);

                $wrote = $wrote || $grant->wasRecentlyCreated;
            }

            // Remembered so a fluent where() can refine this exact concession;
            // a fresh to() starts a fresh constraint set.
            $this->lastGranted = $permissions;
            $this->lastAuthority = $authority;
            $this->lastScope = $scope;
            $this->constraints = null;

            // A write that wrote nothing announces nothing, as removals already
            // do. The chain state above still moves, so where() can refine it.
            if (! $wrote) {
                return;
            }

            $this->bumpCacheVersion($scope);

            $this->dispatchWardenEvent($this->forbidding
                ? new PermissionForbidden($authority, new Collection($permissions), $scope, $this->actor())
                : new PermissionGranted($authority, new Collection($permissions), $scope, $this->actor()));
        });
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    private function permitsGrant(string|array|Model|BackedEnum $permissions, Model|string|null $entity, bool $onlyOwned): bool
    {
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
     * Every catalog row of this permission's shape, conditions aside.
     *
     * @return list<int|string>
     */
    private function siblingKeys(Model $permission): array
    {
        $keys = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('name', $permission->getAttribute('name'))
            ->where('entity_type', $permission->getAttribute('entity_type'))
            ->where('entity_id', $permission->getAttribute('entity_id'))
            ->where('only_owned', $permission->getAttribute('only_owned'))
            ->where('scope', $permission->getAttribute('scope'))
            ->toBase()
            ->pluck('id')
            ->all();

        /** @var list<int|string> $keys */
        return $keys;
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

        $grantClass = Context::resolve()->grantClass();
        $options = ConstraintSerializer::serialize($group);

        /** @var list<array{0: Model, 1: Model}> $repointed */
        $repointed = [];

        // Deleting a grant and re-creating it against the twin is one edit: a
        // failure between the two would leave the concession simply gone.
        (new ($grantClass))->getConnection()->transaction(function () use (&$repointed, $grantClass, $options): void {
            foreach ($this->lastGranted as $index => $permission) {
                $twin = $this->twinWithOptions($permission, $options);

                if ($twin->is($permission)) {
                    continue; // @codeCoverageIgnore
                }

                // Every sibling twin of this shape, not just the row resolved here:
                // a second where() EDITS the rule, and leaving the previous twin's
                // grant alive would make the two conditions authorise as a union.
                $grantClass::query()->withoutGlobalScope(TenantScope::class)
                    ->whereIn('permission_id', $this->siblingKeys($permission))
                    ->where('entity_type', $this->lastAuthority?->getMorphClass())
                    ->where('entity_id', $this->lastAuthority?->getKey())
                    ->where('forbidden', $this->forbidding)
                    ->where('scope', $this->lastScope)
                    ->delete();

                $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                    'permission_id' => $this->modelKey($twin),
                    'entity_type' => $this->lastAuthority?->getMorphClass(),
                    'entity_id' => $this->lastAuthority?->getKey(),
                    'forbidden' => $this->forbidding,
                    'scope' => $this->lastScope,
                ]);

                // A base row this action just created, now orphaned, goes away.
                $orphaned = $permission->wasRecentlyCreated
                    && ! $grantClass::query()->withoutGlobalScope(TenantScope::class)
                        ->where('permission_id', $this->modelKey($permission))
                        ->exists();

                if ($orphaned) {
                    $permission->delete();
                }

                $this->lastGranted[$index] = $twin;
                $repointed[] = [$permission, $twin];
            }
        });

        $this->bumpCacheVersion($this->lastScope);

        // A narrowing chain is two writes: the audit trail says so, in order.
        $this->dispatchWardenEvent($this->forbidding
            ? new PermissionUnforbidden($this->lastAuthority, new Collection(array_column($repointed, 0)), $this->lastScope, $this->actor())
            : new PermissionRevoked($this->lastAuthority, new Collection(array_column($repointed, 0)), $this->lastScope, $this->actor()));

        $this->dispatchWardenEvent($this->forbidding
            ? new PermissionForbidden($this->lastAuthority, new Collection(array_column($repointed, 1)), $this->lastScope, $this->actor())
            : new PermissionGranted($this->lastAuthority, new Collection(array_column($repointed, 1)), $this->lastScope, $this->actor()));

        return $this;
    }

    /**
     * @param  array{v: int, g: array<string, mixed>}  $options
     */
    private function twinWithOptions(Model $base, array $options): Model
    {
        $permissionClass = Context::resolve()->permissionClass();

        // Options compare in PHP: JSON equality is not portable across engines.
        $candidates = $permissionClass::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('name', $base->getAttribute('name'))
            ->where('entity_type', $base->getAttribute('entity_type'))
            ->where('entity_id', $base->getAttribute('entity_id'))
            ->where('only_owned', $base->getAttribute('only_owned'))
            ->where('scope', $base->getAttribute('scope'))
            ->get();

        foreach ($candidates as $candidate) {
            if (ConstraintSerializer::sameRule($candidate->getAttribute('options'), $options)) {
                return $candidate;
            }
        }

        return $permissionClass::query()->create([
            'name' => $base->getAttribute('name'),
            'entity_type' => $base->getAttribute('entity_type'),
            'entity_id' => $base->getAttribute('entity_id'),
            'only_owned' => $base->getAttribute('only_owned'),
            'options' => $options,
            'scope' => $base->getAttribute('scope'),
        ]);
    }
}
