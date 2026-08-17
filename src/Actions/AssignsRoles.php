<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Database\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Database\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AssignsRoles
{
    use NormalizesRoles;

    /** @var list<string|Model> */
    private readonly array $roles;

    /**
     * @param  string|array<int, mixed>|Model  $roles
     */
    public function __construct(string|array|Model $roles)
    {
        $this->roles = $this->normalizeRoles($roles);
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

        foreach ($this->resolveRoleModels($this->roles) as $role) {
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

        return $this;
    }
}
