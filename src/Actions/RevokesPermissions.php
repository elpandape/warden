<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Actions\Concerns\RemovesByKey;
use ElPandaPe\Warden\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Warden\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\RevokingPermission;
use ElPandaPe\Warden\Events\UnforbiddingPermission;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class RevokesPermissions
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use RemovesByKey;
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidden = false;

    public function __construct(private readonly Model|string|null $authority) {}

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    public function to(string|array|Model|BackedEnum $permissions, Model|string|null $entity = null): static
    {
        return $this->revoke($permissions, $entity, onlyOwned: false);
    }

    /**
     * Revoke ownership-scoped grants only — plain grants stay untouched.
     *
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    public function toOwn(Model|string $entity, string|array|Model|BackedEnum $permissions = '*'): static
    {
        return $this->revoke($permissions, $entity, onlyOwned: true);
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    public function toOwnEverything(string|array|Model|BackedEnum $permissions = '*'): static
    {
        return $this->toOwn('*', $permissions);
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
     * The pre-event announces the scope the delete will actually target —
     * computable without side effects: a string authority names a role.
     *
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    private function permitsRemoval(string|array|Model|BackedEnum $permissions, Model|string|null $entity, bool $onlyOwned): bool
    {
        $roleAuthority = is_string($this->authority)
            || $this->authority instanceof (Context::resolve()->roleClass());

        $scope = app(Tenancy::class)->writeScope(forRoleGrant: $roleAuthority);
        $names = $this->permissionNames($permissions);

        return $this->eventPermits(fn (): UnforbiddingPermission|RevokingPermission => $this->forbidden
            ? new UnforbiddingPermission($this->authority, $names, $entity, $scope, $onlyOwned, operation: $this->operation())
            : new RevokingPermission($this->authority, $names, $entity, $scope, $onlyOwned, operation: $this->operation()));
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    private function revoke(string|array|Model|BackedEnum $permissions, Model|string|null $entity, bool $onlyOwned): static
    {
        return $this->asOneWrite(function () use ($permissions, $entity, $onlyOwned): static {
            $context = Context::resolve();

            if (! $this->permitsRemoval($permissions, $entity, $onlyOwned)) {
                return $this;
            }

            // Resolve first: revoking from a role that does not exist must fail fast.
            $authority = $this->authority === null
                ? null
                : $this->resolveAuthority($this->authority, createRole: false);

            $requested = $this->inRequestOrder(
                $this->normalizePermissions($permissions),
                $this->findPermissions($permissions, $entity, $onlyOwned),
            );

            if ($requested === []) {
                return $this;
            }

            // Deletes target the exact write scope: global rows survive tenant-scoped revokes.
            $scope = app(Tenancy::class)->writeScope(
                forRoleGrant: $authority instanceof ($context->roleClass()),
            );

            $query = $context->grantClass()::query()->withoutGlobalScope(TenantScope::class);
            $key = $query->getModel()->getKeyName();

            $rows = $query
                ->whereIn('permission_id', array_keys($requested))
                ->where('forbidden', $this->forbidden)
                ->where('entity_type', $authority?->getMorphClass())
                ->where('entity_id', $authority?->getKey())
                ->where('scope', $scope)
                ->orderBy($key)
                ->get([$key, 'permission_id', 'expires_at']);

            $removed = [];

            foreach ($this->deleteByKey($rows) as $row) {
                $removed[$row->permission_id][] = $row;
            }

            $grants = [];

            foreach ($requested as $permissionKey => $permission) {
                foreach ($removed[$permissionKey] ?? [] as $row) {
                    $grants[] = new GrantRemoval($permission, Expiry::of($row));
                }
            }

            if ($grants === []) {
                return $this;
            }

            $this->bumpCacheVersion($scope);

            $lost = collect($grants)->map(fn (GrantRemoval $grant): Model => $grant->permission)->uniqueStrict()->values();

            $this->dispatchWardenEvent(fn (): PermissionUnforbidden|PermissionRevoked => $this->forbidden
                ? new PermissionUnforbidden($authority, $lost, $scope, actor: $this->actor(), grants: $grants, operation: $this->operation())
                : new PermissionRevoked($authority, $lost, $scope, actor: $this->actor(), grants: $grants, operation: $this->operation()));

            return $this;
        });
    }
}
