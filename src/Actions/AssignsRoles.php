<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use BackedEnum;
use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\AssigningRole;
use ElPandaPe\Bouncer\Events\Concerns\DispatchesEvents;
use ElPandaPe\Bouncer\Events\RoleAssigned;
use ElPandaPe\Bouncer\Exceptions\ConfigurationException;
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

    private ?Model $restrictedTo = null;

    private bool $assigned = false;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles, bool $silentEvents = false)
    {
        $this->roles = $this->normalizeRoles($roles);
        $this->silentEvents = $silentEvents;
    }

    /**
     * Restrict the assignment to one context model: the role's grants only
     * apply to entities belonging to it. Call before to() — writes are
     * immediate, and the same role may repeat across contexts.
     */
    public function on(Model $context): static
    {
        if ($this->assigned) {
            throw new ConfigurationException('Call on() before to(): assignments execute immediately.');
        }

        if (! $context->exists) {
            throw new ConfigurationException('The restriction context must be a saved model.');
        }

        $key = $context->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new ConfigurationException('The restriction context must have a usable key.');
        }

        $this->restrictedTo = $context;

        return $this;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function to(Model|array $authorities): static
    {
        $this->assigned = true;

        $assignedRole = Context::resolve()->assignedRoleClass();
        $targets = $this->normalizeAuthorities($authorities);

        // Assignments live in the exact current scope: lookup and creation agree.
        $scope = app(Tenancy::class)->writeScope();

        if (! $this->eventPermits(new AssigningRole($this->roles, $targets, $scope, $this->restrictedTo))) {
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
                    'restricted_to_type' => $this->restrictedTo?->getMorphClass(),
                    'restricted_to_id' => $this->restrictedTo?->getKey(),
                    'scope' => $scope,
                ]);
            }
        }

        $this->bumpCacheVersion($scope);

        foreach ($targets as $authority) {
            $this->dispatchBouncerEvent(new RoleAssigned($authority, new Collection($models), $scope, $this->restrictedTo));
        }

        return $this;
    }
}
