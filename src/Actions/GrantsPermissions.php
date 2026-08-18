<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use BackedEnum;
use Closure;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Constraints\Builder;
use ElPandaPe\Bouncer\Constraints\ConstraintSerializer;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\ForbiddingPermission;
use ElPandaPe\Bouncer\Events\GrantingPermission;
use ElPandaPe\Bouncer\Events\PermissionForbidden;
use ElPandaPe\Bouncer\Events\PermissionGranted;
use ElPandaPe\Bouncer\Exceptions\ConfigurationException;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
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
            return $this;
        }

        $this->grant($this->findOrCreatePermissions($permissions, $entity));

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
            return $this;
        }

        $this->grant($this->findOrCreatePermissions($permissions, $entity, onlyOwned: true));

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

        foreach ($permissions as $permission) {
            // firstOrCreate self-heals concurrent races via createOrFirst on Laravel 12+.
            $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                'permission_id' => $this->modelKey($permission),
                'entity_type' => $authority?->getMorphClass(),
                'entity_id' => $authority?->getKey(),
                'forbidden' => $this->forbidding,
                'scope' => $scope,
            ]);
        }

        // Remembered so a fluent where() can refine this exact concession;
        // a fresh to() starts a fresh constraint set.
        $this->lastGranted = $permissions;
        $this->lastAuthority = $authority;
        $this->lastScope = $scope;
        $this->constraints = null;

        $this->bumpCacheVersion($scope);

        $this->dispatchBouncerEvent($this->forbidding
            ? new PermissionForbidden($authority, new Collection($permissions), $scope)
            : new PermissionGranted($authority, new Collection($permissions), $scope));
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
     * Distinct constraints mean a distinct catalog row: the grant is
     * re-pointed to a twin permission carrying the serialized group, so a
     * shared unconstrained row is never mutated under other holders.
     */
    private function reconstrain(): static
    {
        if ($this->lastGranted === []) {
            throw new ConfigurationException('Constraints need a grant to refine: call to() or toOwn() first.');
        }

        $grantClass = Context::resolve()->grantClass();
        $options = ConstraintSerializer::serialize($this->builder()->group());

        foreach ($this->lastGranted as $index => $permission) {
            $twin = $this->twinWithOptions($permission, $options);

            if ($twin->is($permission)) {
                continue; // @codeCoverageIgnore
            }

            $grantClass::query()->withoutGlobalScope(TenantScope::class)
                ->where('permission_id', $this->modelKey($permission))
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
        }

        $this->bumpCacheVersion($this->lastScope);

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
            if ($this->optionsMatch($candidate->getAttribute('options'), $options)) {
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

    /**
     * Strict, order-insensitive comparison: engines may reorder JSON object
     * keys, but value types must match exactly — '1' and 1 are different
     * constraints.
     */
    private function optionsMatch(mixed $stored, mixed $target): bool
    {
        return $this->normalizedOptions($stored) === $this->normalizedOptions($target);
    }

    private function normalizedOptions(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map($this->normalizedOptions(...), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     * @return list<string>
     */
    private function permissionNames(string|array|Model|BackedEnum $permissions): array
    {
        $names = [];

        foreach ($this->normalizePermissions($permissions) as $permission) {
            $name = $permission instanceof Model ? $permission->getAttribute('name') : $permission;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
