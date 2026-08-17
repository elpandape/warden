<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use BackedEnum;
use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\AssigningRole;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\RoleAssigned;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AssignsRoles
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;

    /** @var list<string|Model> */
    private readonly array $roles;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles, bool $silentEvents = false)
    {
        $this->roles = $this->normalizeRoles($roles);
        $this->silentEvents = $silentEvents;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function to(Model|array $authorities): static
    {
        $assignedRole = Context::resolve()->assignedRoleClass();
        $targets = $this->normalizeAuthorities($authorities);

        // Assignments live in the exact current scope: lookup and creation agree.
        $scope = app(Tenancy::class)->writeScope();

        if (! $this->eventPermits(new AssigningRole($this->roles, $targets, $scope))) {
            return $this;
        }

        $models = $this->resolveRoleModels($this->roles);

        foreach ($models as $role) {
            $roleKey = $this->modelKey($role);

            foreach ($targets as $authority) {
                $assignedRole::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                    'role_id' => $roleKey,
                    'entity_type' => $authority->getMorphClass(),
                    'entity_id' => $authority->getKey(),
                    'scope' => $scope,
                ]);
            }
        }

        $this->bumpCacheVersion($scope);

        foreach ($targets as $authority) {
            $this->dispatchBouncerEvent(new RoleAssigned($authority, new Collection($models), $scope));
        }

        return $this;
    }
}
