<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RetractsRoles
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;

    /** @var list<string|Model> */
    private readonly array $roles;

    private ?Model $restrictedTo = null;

    private bool $retracted = false;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles)
    {
        $this->roles = $this->normalizeRoles($roles);
    }

    /**
     * Retract only the assignment restricted to this context. Without on(),
     * every assignment of the role goes, restricted ones included.
     */
    public function on(Model $context): static
    {
        if ($this->retracted) {
            throw new ConfigurationException('Call on() before from(): retractions execute immediately.');
        }

        $this->restrictedTo = $context;

        return $this;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function from(Model|array $authorities): static
    {
        $this->retracted = true;
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
                ->when(
                    $this->restrictedTo instanceof Model,
                    /** @param Builder<Model> $query */
                    function (Builder $query): void {
                        $query->where('restricted_to_type', $this->restrictedTo?->getMorphClass())
                            ->where('restricted_to_id', $this->restrictedTo?->getKey());
                    },
                )
                ->delete();

            if ($deleted > 0) {
                $this->bumpCacheVersion($scope);
                $this->dispatchWardenEvent(
                    new RoleRetracted($authority, new Collection($models), $scope, $this->restrictedTo),
                );
            }
        }

        return $this;
    }
}
