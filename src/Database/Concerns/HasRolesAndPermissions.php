<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Database\AssignedRole;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait HasRolesAndPermissions
{
    use HasPermissions;

    /**
     * @return MorphToMany<Model, $this, AssignedRole>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->modelClass('role');
        /** @var class-string<AssignedRole> $assignedRole */
        $assignedRole = $context->modelClass('assigned_role');

        $relation = $this
            ->morphToMany($role, 'entity', $context->table('assigned_roles'), relatedPivotKey: 'role_id')
            ->using($assignedRole)
            ->withPivot(['scope', 'restricted_to_type', 'restricted_to_id']);

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }

    public function isA(string ...$roles): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->intersect(array_values($roles))->isNotEmpty();
        }

        return $this->roles()->whereIn('name', $roles)->exists();
    }

    public function isAn(string ...$roles): bool
    {
        return $this->isA(...$roles);
    }

    public function isNotA(string ...$roles): bool
    {
        return ! $this->isA(...$roles);
    }

    public function isNotAn(string ...$roles): bool
    {
        return $this->isNotA(...$roles);
    }

    public function isAll(string ...$roles): bool
    {
        $unique = array_values(array_unique($roles));

        if ($this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->intersect($unique)->count() === count($unique);
        }

        return $this->roles()->whereIn('name', $unique)->distinct()->count('name') === count($unique);
    }

    /**
     * @return Collection<int, mixed>
     */
    private function loadedRoleNames(): Collection
    {
        /** @var Collection<int, Model> $loaded */
        $loaded = $this->getRelation('roles');

        return $loaded->pluck('name');
    }
}
