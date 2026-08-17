<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use BackedEnum;
use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\RoleRetracted;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RetractsRoles
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;

    /** @var list<string|Model> */
    private readonly array $roles;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles)
    {
        $this->roles = $this->normalizeRoles($roles);
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function from(Model|array $authorities): static
    {
        $context = Context::resolve();
        $roleClass = $context->roleClass();
        $assignedRole = $context->assignedRoleClass();

        $names = [];
        /** @var list<Model> $models */
        $models = [];

        foreach ($this->roles as $role) {
            if ($role instanceof Model) {
                $models[] = $this->assertModelOf($role, $roleClass, 'role');
            } else {
                $names[] = $role;
            }
        }

        if ($names !== []) {
            foreach ($roleClass::query()->whereIn('name', $names)->get() as $found) {
                $models[] = $found;
            }
        }

        $keys = array_map($this->modelKey(...), $models);

        // Deletes target the exact write scope: global assignments survive tenant retracts.
        $scope = app(Tenancy::class)->writeScope();

        foreach ($this->normalizeAuthorities($authorities) as $authority) {
            $deleted = $assignedRole::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('role_id', $keys)
                ->where('entity_type', $authority->getMorphClass())
                ->where('entity_id', $authority->getKey())
                ->where('scope', $scope)
                ->delete();

            if ($deleted > 0) {
                $this->bumpCacheVersion($scope);
                $this->dispatchBouncerEvent(new RoleRetracted($authority, new Collection($models), $scope));
            }
        }

        return $this;
    }
}
