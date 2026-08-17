<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class GrantsPermissions
{
    use Concerns\BumpsCacheVersion;
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidding = false;

    public function __construct(private readonly Model|string|null $authority) {}

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     */
    public function to(string|array|Model $permissions, Model|string|null $entity = null): static
    {
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
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwn(Model|string $entity, string|array $permissions = '*'): static
    {
        $this->grant($this->findOrCreatePermissions($permissions, $entity, onlyOwned: true));

        return $this;
    }

    /**
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwnEverything(string|array $permissions = '*'): static
    {
        return $this->toOwn('*', $permissions);
    }

    /**
     * @param  list<int|string>  $permissionKeys
     */
    protected function grant(array $permissionKeys): void
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

        foreach ($permissionKeys as $key) {
            // firstOrCreate self-heals concurrent races via createOrFirst on Laravel 12+.
            $grantClass::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                'permission_id' => $key,
                'entity_type' => $authority?->getMorphClass(),
                'entity_id' => $authority?->getKey(),
                'forbidden' => $this->forbidding,
                'scope' => $scope,
            ]);
        }

        $this->bumpCacheVersion($scope);
    }
}
