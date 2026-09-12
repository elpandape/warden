<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Concerns;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Support\Name;
use ElPandaPe\Warden\Support\RoleClosure;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait HasRolesAndPermissions
{
    use HasPermissions;

    /**
     * @return MorphToMany<\ElPandaPe\Warden\Models\Role, $this, AssignedRole>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->roleClass();
        $assignedRole = $context->assignedRoleClass();

        $relation = $this
            ->scopedMorphToMany($role, $context->table('assigned_roles'), 'entity_id', 'role_id', 'roles', inverse: false, roleGrant: false)
            ->using($assignedRole)
            ->withPivot(['scope', 'restricted_to_type', 'restricted_to_id', 'expires_at']);

        $relation = $this->applyPivotTenancy($relation, $context->table('assigned_roles'));

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }

    public function isA(string|BackedEnum ...$roles): bool
    {
        $names = array_map(Name::of(...), array_values($roles));

        if (! Config::nestedRoles() && $this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->intersect($names)->isNotEmpty();
        }

        if (! Config::nestedRoles()) {
            return $this->roles()->whereIn('name', $names)->tap(self::onlyLive(...))->exists();
        }

        // Nesting reaches roles the eager-loaded relation never held, so the
        // memo cannot answer this one.
        $reachable = array_keys(RoleClosure::for($this));

        return $reachable !== [] && Context::resolve()->roleClass()::query()
            ->whereKey($reachable)
            ->whereIn('name', $names)
            ->exists();
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

        // Nesting reaches roles the eager-loaded relation never held, so the
        // memo cannot answer this one either.
        if (Config::nestedRoles()) {
            return Context::resolve()->roleClass()::query()
                ->whereKey(array_keys(RoleClosure::for($this)))
                ->whereIn('name', $unique)
                ->distinct()
                ->count('name') === count($unique);
        }

        if ($this->relationLoaded('roles')) {
            return $this->loadedRoleNames()->unique()->intersect($unique)->count() === count($unique);
        }

        return $this->roles()
            ->whereIn('name', $unique)
            ->tap(self::onlyLive(...))
            ->distinct()
            ->count('name') === count($unique);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIs(Builder $query, string|BackedEnum ...$roles): Builder
    {
        return $query->whereHas('roles', self::liveAssignmentOf(array_map(Name::of(...), array_values($roles))));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIsAll(Builder $query, string|BackedEnum ...$roles): Builder
    {
        foreach (array_unique(array_map(Name::of(...), $roles)) as $name) {
            $query->whereHas('roles', self::liveAssignmentOf([$name]));
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereIsNot(Builder $query, string|BackedEnum ...$roles): Builder
    {
        return $query->whereDoesntHave('roles', self::liveAssignmentOf(array_map(Name::of(...), array_values($roles))));
    }

    /**
     * Every permission granted to this authority — directly, through an
     * unrestricted role, or to everyone — under the current filters.
     * The silber/bouncer getAbilities() equivalent, for migrators.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public function getPermissions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->resolveGrantedPermissions(forbidden: false);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public function getForbiddenPermissions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->resolveGrantedPermissions(forbidden: true);
    }

    /**
     * @param  list<string>  $names
     * @return list<int|string>
     */
    private static function roleKeysNamed(array $names): array
    {
        /** @var list<int|string> */
        return Context::resolve()->roleClass()::query()
            ->whereIn('name', $names)
            ->toBase()
            ->pluck('id')
            ->all();
    }

    /**
     * Qualified outside the whereHas closures: the role table may be renamed.
     */
    private static function qualifiedRoleName(): string
    {
        return (new (Context::resolve()->roleClass()))->qualifyColumn('name');
    }

    /**
     * Expiry is filtered by each role check, on the pivot, and never inside
     * roles(): that relation is also what $user->roles lists.
     */
    private static function onlyLive(QueryBuilder $query): void
    {
        Expiry::live($query, Context::resolve()->table('assigned_roles'));
    }

    /**
     * The role row the three scopes look for through roles(): a live
     * assignment of one of the names or, with nesting on, of a role that
     * reaches one. Nesting is answered from the role's side: a closure
     * cannot be expanded per row.
     *
     * @param  list<string>  $names
     */
    private static function liveAssignmentOf(array $names): Closure
    {
        if (! Config::nestedRoles()) {
            $column = self::qualifiedRoleName();

            return fn (Builder $role): Builder => $role->whereIn($column, $names)->tap(self::onlyLive(...));
        }

        $reaching = RoleClosure::reaching(self::roleKeysNamed($names));

        return fn (Builder $role): Builder => $role->whereKey($reaching)->tap(self::onlyLive(...));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    private function resolveGrantedPermissions(bool $forbidden): \Illuminate\Database\Eloquent\Collection
    {
        $context = Context::resolve();
        $roleMorph = (new ($context->roleClass()))->getMorphClass();

        $roleKeys = $context->assignedRoleClass()::query()
            ->where('entity_type', $this->getMorphClass())
            ->where('entity_id', $this->getKey())
            ->whereNull('restricted_to_type')
            ->whereNull('restricted_to_id')
            ->tap(Expiry::live(...))
            ->toBase()
            ->pluck('role_id')
            ->all();

        // getPermissions() filters expiry like the resolver does. A listing that
        // hands back an expired permission paints a menu can() then denies.
        $permissionKeys = $context->grantClass()::query()
            ->where('forbidden', $forbidden)
            ->tap(Expiry::live(...))
            ->where(
                /** @param Builder<\ElPandaPe\Warden\Models\Grant> $query */
                function (Builder $query) use ($roleMorph, $roleKeys): void {
                    $query
                        ->where(
                            /** @param Builder<\ElPandaPe\Warden\Models\Grant> $direct */
                            function (Builder $direct): void {
                                $direct->where('entity_type', $this->getMorphClass())
                                    ->where('entity_id', $this->getKey());
                            },
                        )
                        ->orWhere(
                            /** @param Builder<\ElPandaPe\Warden\Models\Grant> $viaRole */
                            function (Builder $viaRole) use ($roleMorph, $roleKeys): void {
                                $viaRole->where('entity_type', $roleMorph)
                                    ->whereIn('entity_id', $roleKeys);
                            },
                        )
                        // A holder type without its key names nobody, not everyone.
                        ->orWhere(
                            /** @param Builder<\ElPandaPe\Warden\Models\Grant> $everyone */
                            function (Builder $everyone): void {
                                $everyone->whereNull('entity_type')->whereNull('entity_id');
                            },
                        );
                },
            )
            ->toBase()
            ->pluck('permission_id')
            ->all();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Model> */
        return $context->permissionClass()::query()->whereKey($permissionKeys)->get();
    }

    /**
     * The eager-loaded fast path filters what the queries filter. By pivot
     * scope: rows loaded under a different tenant never leak into the current
     * one (fail-closed). By end date: an assignment that expired after the
     * load stops counting at the same instant the queries drop it.
     *
     * @return Collection<int, mixed>
     */
    private function loadedRoleNames(): Collection
    {
        /** @var Collection<int, Model> $loaded */
        $loaded = $this->getRelation('roles');

        $now = Carbon::now()->getTimestamp();

        $loaded = $loaded->filter(function (Model $role) use ($now): bool {
            $pivot = $role->getRelationValue('pivot');
            $ends = $pivot instanceof Model ? $pivot->getAttribute('expires_at') : null;

            // A pivot swapped in without warden's datetime cast reads back the stored text.
            if (is_string($ends)) {
                $ends = Carbon::parse($ends);
            }

            return ! $ends instanceof DateTimeInterface || $ends->getTimestamp() > $now;
        });

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
