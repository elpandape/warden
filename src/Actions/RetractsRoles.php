<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\Model;

class RetractsRoles
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
    public function from(Model|array $authorities): static
    {
        $context = Context::resolve();
        $roleClass = $context->roleClass();
        $assignedRole = $context->assignedRoleClass();

        $names = [];
        $keys = [];

        foreach ($this->roles as $role) {
            if ($role instanceof Model) {
                $keys[] = $this->modelKey($this->assertModelOf($role, $roleClass, 'role'));
            } else {
                $names[] = $role;
            }
        }

        if ($names !== []) {
            $query = $roleClass::query()->whereIn('name', $names);
            $keyName = $query->getModel()->getKeyName();

            foreach ($query->getQuery()->pluck($keyName) as $key) {
                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }
        }

        foreach ($this->normalizeAuthorities($authorities) as $authority) {
            $assignedRole::query()
                ->whereIn('role_id', $keys)
                ->where('entity_type', $authority->getMorphClass())
                ->where('entity_id', $authority->getKey())
                ->delete();
        }

        return $this;
    }
}
