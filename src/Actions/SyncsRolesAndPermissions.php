<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use ElPandaPe\Warden\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Warden\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Warden\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\PermissionsSynced;
use ElPandaPe\Warden\Events\RolesSynced;
use ElPandaPe\Warden\Events\SyncResult;
use ElPandaPe\Warden\Support\LiveRoles;
use ElPandaPe\Warden\Support\Operations;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class SyncsRolesAndPermissions
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;
    use ResolvesAuthority;
    use ResolvesPermissions;

    public function __construct(private readonly Model|string $authority) {}

    /**
     * @param  array<int, mixed>  $roles
     */
    public function roles(array $roles): static
    {
        return app(Operations::class)->during(fn (): static => $this->syncRoles($roles));
    }

    /**
     * @param  array<int, mixed>  $permissions
     */
    public function permissions(array $permissions): static
    {
        return app(Operations::class)->during(fn (): static => $this->syncGrants($permissions, forbidden: false));
    }

    /**
     * @param  array<int, mixed>  $permissions
     */
    public function forbiddenPermissions(array $permissions): static
    {
        return app(Operations::class)->during(fn (): static => $this->syncGrants($permissions, forbidden: true));
    }

    /**
     * @param  array<int, mixed>  $roles
     */
    private function syncRoles(array $roles): static
    {
        $context = Context::resolve();
        $authority = $this->resolveAuthority($this->authority, createRole: true);
        $assignedRole = $context->assignedRoleClass();

        $models = $this->resolveRoleModels($this->normalizeRoles($roles));
        $keys = array_map($this->modelKey(...), $models);

        $scope = app(Tenancy::class)->writeScope();

        // Sync declares the unrestricted set: context-scoped assignments are
        // orthogonal and stay untouched — manage them with assign()/retract()->on().
        $beforeKeys = $assignedRole::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('scope', $scope)
            ->whereNull('restricted_to_type')
            ->whereNull('restricted_to_id')
            ->toBase()
            ->pluck('role_id')
            ->all();

        // Sync is per-scope: rows in other tenants and global rows stay untouched.
        // It declares the live set: a trashed role's assignment stays for restore().
        $assignedRole::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('scope', $scope)
            ->whereNull('restricted_to_type')
            ->whereNull('restricted_to_id')
            ->whereNotIn('role_id', $keys)
            ->tap(static function (Builder $query): void {
                LiveRoles::only($query, 'role_id');
            })
            ->delete();

        $this->bumpCacheVersion($scope);

        if ($models !== []) {
            // Silent: the diffed sync event below already tells the whole story.
            new AssignsRoles($models, silentEvents: true)->to($authority);
        }

        /** @var Collection<int, Model> $before */
        $before = $context->roleClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($this->usableKeys($beforeKeys))
            ->get()
            ->toBase();

        $this->dispatchWardenEvent(fn (): RolesSynced => new RolesSynced($authority, $this->diff($models, $before, $beforeKeys), $scope, actor: $this->actor(), operation: $this->operation()));

        return $this;
    }

    /**
     * @param  array<int, mixed>  $permissions
     */
    private function syncGrants(array $permissions, bool $forbidden): static
    {
        $context = Context::resolve();
        $authority = $this->resolveAuthority($this->authority, createRole: true);
        $grantClass = $context->grantClass();

        $permissionModels = $permissions === []
            ? []
            : $this->findOrCreatePermissions($permissions, entity: null);

        $keys = array_map($this->modelKey(...), $permissionModels);

        // Sync is per-scope; role grants may stay global by configuration.
        $scope = app(Tenancy::class)->writeScope(
            forRoleGrant: $authority instanceof ($context->roleClass()),
        );

        $permission = new ($context->permissionClass());
        $permissionTable = $permission->getTable();
        $trash = $this->trashColumn($permission);

        // A name resolves to the plain row only, so the sweep reaches only
        // what this call could have declared. An entity-scoped rule is not
        // absent from the declaration: it was never expressible in it, and a
        // trashed one is not absent either: a sync declares the live set.
        $plainRows = function (QueryBuilder $query) use ($permissionTable, $trash): void {
            $query->select('id')->from($permissionTable)
                ->whereNull('entity_type')
                ->whereNull('options')
                ->where('only_owned', false);

            if ($trash !== null) {
                $query->whereNull($trash);
            }
        };

        // The same reach plus any rule the call named as a model, so detached
        // lists exactly what the sweep below deletes.
        $beforeKeys = $grantClass::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('forbidden', $forbidden)
            ->where('scope', $scope)
            ->toBase()
            ->where(function (QueryBuilder $query) use ($plainRows, $keys): void {
                $query->whereIn('permission_id', $plainRows)->orWhereIn('permission_id', $keys);
            })
            ->pluck('permission_id')
            ->all();

        $grantClass::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('forbidden', $forbidden)
            ->where('scope', $scope)
            ->whereNotIn('permission_id', $keys)
            ->whereIn('permission_id', $plainRows)
            ->delete();

        foreach ($keys as $key) {
            $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                'permission_id' => $key,
                'entity_type' => $authority->getMorphClass(),
                'entity_id' => $authority->getKey(),
                'forbidden' => $forbidden,
                'scope' => $scope,
            ]);
        }

        $this->bumpCacheVersion($scope);

        /** @var Collection<int, Model> $before */
        $before = $context->permissionClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($this->usableKeys($beforeKeys))
            ->get()
            ->toBase();

        $this->dispatchWardenEvent(
            fn (): PermissionsSynced => new PermissionsSynced($authority, $this->diff($permissionModels, $before, $beforeKeys), $scope, $forbidden, actor: $this->actor(), operation: $this->operation()),
        );

        return $this;
    }

    /**
     * The diff against the pre-sync state, with hydrated models on every
     * side. $rawBeforeKeys, read before hydration, still names a row a global
     * scope on the model — the trash included — would otherwise hide from
     * $before: such a row is already held, so it must not print as attached.
     *
     * @param  list<Model>  $target
     * @param  Collection<int, Model>  $before
     * @param  array<array-key, mixed>  $rawBeforeKeys
     */
    private function diff(array $target, Collection $before, array $rawBeforeKeys): SyncResult
    {
        $beforeKeys = array_map(fn (int|string $key): string => (string) $key, $this->usableKeys($rawBeforeKeys));
        $targetKeys = array_map(fn (Model $model): string => (string) $this->modelKey($model), $target);

        $attached = array_values(array_filter(
            $target,
            fn (Model $model): bool => ! in_array((string) $this->modelKey($model), $beforeKeys, true),
        ));

        $kept = $before->filter(
            fn (Model $model): bool => in_array((string) $this->modelKey($model), $targetKeys, true),
        )->values();

        $detached = $before->filter(
            fn (Model $model): bool => ! in_array((string) $this->modelKey($model), $targetKeys, true),
        )->values();

        return new SyncResult(new Collection($attached), $detached, $kept);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return list<int|string>
     */
    private function usableKeys(array $keys): array
    {
        return array_values(array_filter($keys, fn (mixed $key): bool => is_int($key) || is_string($key)));
    }
}
