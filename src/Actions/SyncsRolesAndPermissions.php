<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class SyncsRolesAndPermissions
{
    use NormalizesRoles;
    use ResolvesAuthority;
    use ResolvesPermissions;

    public function __construct(private readonly Model|string $authority) {}

    /**
     * @param  array<int, mixed>  $roles
     */
    public function roles(array $roles): static
    {
        $authority = $this->resolveAuthority($this->authority, createRole: true);
        $assignedRole = Context::resolve()->assignedRoleClass();

        $models = $this->resolveRoleModels($this->normalizeRoles($roles));
        $keys = array_map($this->modelKey(...), $models);

        // Sync is per-scope: rows in other tenants and global rows stay untouched.
        $assignedRole::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('scope', app(Tenancy::class)->writeScope())
            ->whereNotIn('role_id', $keys)
            ->delete();

        if ($models !== []) {
            new AssignsRoles($models)->to($authority);
        }

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

        $keys = $permissions === []
            ? []
            : $this->findOrCreatePermissions($permissions, entity: null);

        // Sync is per-scope; role grants may stay global by configuration.
        $scope = app(Tenancy::class)->writeScope(
            forRoleGrant: $authority instanceof ($context->roleClass()),
        );

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

        return $this;
    }
}
