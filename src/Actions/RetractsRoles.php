<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\RetractingRole;
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

    private int $retractedCount = 0;

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

        // The same contract the assigning side keeps: an unsaved context cannot
        // match a stored restriction, so accepting it only deletes nothing.
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
     * Assignment rows this retract actually deleted.
     *
     * Reads answer "global or this tenant"; deletes target this tenant only, so
     * a count of zero can mean the authority still holds the role globally, and
     * a non-zero count does not promise the role is gone everywhere.
     */
    public function retractedCount(): int
    {
        return $this->retractedCount;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function from(Model|array $authorities): static
    {
        return $this->asOneWrite(function () use ($authorities): static {
            $this->retracted = true;
            $this->retractedCount = 0;
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

            $targets = $this->normalizeAuthorities($authorities);

            if (! $this->eventPermits(new RetractingRole($this->roles, $targets, $scope, $this->restrictedTo))) {
                return $this;
            }

            foreach ($targets as $authority) {
                /** @var int $deleted */
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

                $this->retractedCount += $deleted;

                if ($deleted > 0) {
                    $this->bumpCacheVersion($scope);
                    $this->dispatchWardenEvent(
                        new RoleRetracted($authority, new Collection($models), $scope, $this->restrictedTo, $this->actor()),
                    );
                }
            }

            return $this;
        });
    }
}
