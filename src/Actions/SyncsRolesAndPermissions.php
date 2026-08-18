<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\PermissionsSynced;
use ElPandaPe\Bouncer\Events\RolesSynced;
use ElPandaPe\Bouncer\Events\SyncResult;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
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
        $assignedRole::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('scope', $scope)
            ->whereNull('restricted_to_type')
            ->whereNull('restricted_to_id')
            ->whereNotIn('role_id', $keys)
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

        $this->dispatchBouncerEvent(new RolesSynced($authority, $this->diff($models, $before), $scope));

        return $this;
    }

    /**
     * @param  array<int, mixed>  $permissions
     */
    public function permissions(array $permissions): static
    {
        return $this->syncGrants($permissions, forbidden: false);
    }

    /**
     * @param  array<int, mixed>  $permissions
     */
    public function forbiddenPermissions(array $permissions): static
    {
        return $this->syncGrants($permissions, forbidden: true);
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

        $beforeKeys = $grantClass::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('forbidden', $forbidden)
            ->where('scope', $scope)
            ->toBase()
            ->pluck('permission_id')
            ->all();

        $grantClass::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('forbidden', $forbidden)
            ->where('scope', $scope)
            ->whereNotIn('permission_id', $keys)
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

        $this->dispatchBouncerEvent(
            new PermissionsSynced($authority, $this->diff($permissionModels, $before), $scope, $forbidden),
        );

        return $this;
    }

    /**
     * The diff against the pre-sync state, with hydrated models on every side.
     *
     * @param  list<Model>  $target
     * @param  Collection<int, Model>  $before
     */
    private function diff(array $target, Collection $before): SyncResult
    {
        $beforeKeys = $before->map(fn (Model $model): string => (string) $this->modelKey($model))->all();
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
