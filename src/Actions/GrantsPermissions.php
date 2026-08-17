<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use BackedEnum;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\ForbiddingPermission;
use ElPandaPe\Bouncer\Events\GrantingPermission;
use ElPandaPe\Bouncer\Events\PermissionForbidden;
use ElPandaPe\Bouncer\Events\PermissionGranted;
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
