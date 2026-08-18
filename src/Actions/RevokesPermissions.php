<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Warden\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RevokesPermissions
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
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
    public function toOwn(Model|string $entity, string|array|BackedEnum $permissions = '*'): static
    {
        return $this->revoke($permissions, $entity, onlyOwned: true);
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    public function toOwnEverything(string|array|BackedEnum $permissions = '*'): static
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
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     */
    private function revoke(string|array|Model|BackedEnum $permissions, Model|string|null $entity, bool $onlyOwned): static
    {
        $context = Context::resolve();

        // Resolve first: revoking from a role that does not exist must fail fast.
        $authority = $this->authority === null
            ? null
            : $this->resolveAuthority($this->authority, createRole: false);

        $permissionModels = $this->findPermissions($permissions, $entity, $onlyOwned);

        if ($permissionModels === []) {
            return $this;
        }

        // Deletes target the exact write scope: global rows survive tenant-scoped revokes.
        $scope = app(Tenancy::class)->writeScope(
            forRoleGrant: $authority instanceof ($context->roleClass()),
        );

        $deleted = $context->grantClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('permission_id', array_map($this->modelKey(...), $permissionModels))
            ->where('forbidden', $this->forbidden)
            ->where('entity_type', $authority?->getMorphClass())
            ->where('entity_id', $authority?->getKey())
            ->where('scope', $scope)
            ->delete() > 0;

        if ($deleted) {
            $this->bumpCacheVersion($scope);

            $this->dispatchWardenEvent($this->forbidden
                ? new PermissionUnforbidden($authority, new Collection($permissionModels), $scope)
                : new PermissionRevoked($authority, new Collection($permissionModels), $scope));
        }

        return $this;
    }
}
