<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Concerns;

use BackedEnum;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Models\AssignedRole;
use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Support\Name;
use ElPandaPe\Bouncer\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait HasRolesAndPermissions
{
    use HasPermissions;

    /**
     * @return MorphToMany<\ElPandaPe\Bouncer\Models\Role, $this, AssignedRole>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->roleClass();
        $assignedRole = $context->assignedRoleClass();

        $relation = $this
            ->morphToMany($role, 'entity', $context->table('assigned_roles'), relatedPivotKey: 'role_id')
            ->using($assignedRole)
            ->withPivot(['scope', 'restricted_to_type', 'restricted_to_id']);

        $relation = $this->applyPivotTenancy($relation, $context->table('assigned_roles'));

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }

    public function isA(string|BackedEnum ...$roles): bool
    {
        $names = array_map(Name::of(...), array_values($roles));

        if ($this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->intersect($names)->isNotEmpty();
        }

        return $this->roles()->whereIn('name', $names)->exists();
    }

    public function isAn(string|BackedEnum ...$roles): bool
    {
        return $this->isA(...$roles);
    }

    public function isNotA(string|BackedEnum ...$roles): bool
    {
        return ! $this->isA(...$roles);
    }

    public function isNotAn(string|BackedEnum ...$roles): bool
    {
        return $this->isNotA(...$roles);
    }

    public function isAll(string|BackedEnum ...$roles): bool
    {
        $unique = array_values(array_unique(array_map(Name::of(...), $roles)));

        if ($this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->intersect($unique)->count() === count($unique);
        }

        return $this->roles()->whereIn('name', $unique)->distinct()->count('name') === count($unique);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIs(Builder $query, string|BackedEnum ...$roles): Builder
    {
        $names = array_map(Name::of(...), array_values($roles));
        $column = self::qualifiedRoleName();

        return $query->whereHas(
            'roles',
            fn (Builder $role): Builder => $role->whereIn($column, $names),
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIsAll(Builder $query, string|BackedEnum ...$roles): Builder
    {
        $column = self::qualifiedRoleName();

        foreach (array_unique(array_map(Name::of(...), $roles)) as $name) {
            $query->whereHas(
                'roles',
                fn (Builder $role): Builder => $role->where($column, $name),
            );
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIsNot(Builder $query, string|BackedEnum ...$roles): Builder
    {
        $names = array_map(Name::of(...), array_values($roles));
        $column = self::qualifiedRoleName();

        return $query->whereDoesntHave(
            'roles',
            fn (Builder $role): Builder => $role->whereIn($column, $names),
        );
    }

    /**
     * Qualified outside the whereHas closures: the role table may be renamed.
     */
    private static function qualifiedRoleName(): string
    {
        return (new (Context::resolve()->roleClass()))->qualifyColumn('name');
    }

    /**
     * The eager-loaded fast path filters by pivot scope: rows loaded under a
     * different tenant never leak into the current one (fail-closed).
     *
     * @return Collection<int, mixed>
     */
    private function loadedRoleNames(): Collection
    {
        /** @var Collection<int, Model> $loaded */
        $loaded = $this->getRelation('roles');

        $filter = app(Tenancy::class)->readFilter();

        if ($filter !== null) {
            $loaded = $loaded->filter(function (Model $role) use ($filter): bool {
                $pivot = $role->getRelationValue('pivot');
                $scope = $pivot instanceof Model ? $pivot->getAttribute('scope') : null;

                if ($scope === null) {
                    return true;
                }

                return $filter[0] === 'both'
                    && (is_int($scope) || is_string($scope))
                    && (string) $scope === (string) $filter[1];
            });
        }

        return $loaded->pluck('name');
    }
}
