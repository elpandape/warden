<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks\Resolvers;

use ElPandaPe\Bouncer\Checks\Verdict;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class DatabaseResolver implements Resolver
{
    public function __construct(private Context $context) {}

    public function resolve(
        Model $authority,
        string $permission,
        Model|string|null $entity = null,
    ): Verdict {
        // A string that is not a model class belongs to app policies: abstain.
        if (is_string($entity) && $entity !== '*' && ! is_subclass_of($entity, Model::class)) {
            return Verdict::abstained();
        }

        // Ownership resolves once per check: user closures may hit the database.
        $owned = $entity instanceof Model && $this->context->isOwnedBy($authority, $entity);

        // Restricted assignments only count when the entity belongs to their
        // context, so the effective role set depends on the check itself.
        $roleKeys = $this->effectiveRoleKeys($authority, $entity);

        // Forbidden always wins: check it before any grant.
        $forbiddenBy = $this->firstMatch($authority, $permission, $entity, $owned, $roleKeys, forbidden: true);

        if ($forbiddenBy !== null) {
            return Verdict::forbidden($forbiddenBy);
        }

        $grantedBy = $this->firstMatch($authority, $permission, $entity, $owned, $roleKeys, forbidden: false);

        return $grantedBy === null ? Verdict::abstained() : Verdict::granted($grantedBy);
    }

    /**
     * @param  list<int|string>  $roleKeys
     */
    private function firstMatch(
        Model $authority,
        string $permission,
        Model|string|null $entity,
        bool $owned,
        array $roleKeys,
        bool $forbidden,
    ): int|string|null {
        $permissionClass = $this->context->permissionClass();
        $permissionModel = new $permissionClass;

        $query = $permissionClass::query()
            ->whereIn('name', [$permission, '*'])
            ->when(! $owned, function (Builder $builder): void {
                $builder->where('only_owned', false);
            })
            ->where(
                /** @param Builder<Permission> $builder */
                fn (Builder $builder) => $this->applyEntityPredicates($builder, $entity),
            )
            ->whereExists(
                fn (QueryBuilder $builder) => $this->applyGrantPredicates($builder, $authority, $permissionModel, $roleKeys, $forbidden),
            )
            ->orderByRaw('entity_id is not null desc, entity_type is not null desc');

        // Resolve through Eloquent so global scopes on custom models keep
        // applying; constraints evaluate per candidate, in specificity order.
        foreach ($query->get() as $candidate) {
            if (! $this->passesConstraints($candidate, $entity, $authority, $forbidden)) {
                continue;
            }

            $key = $candidate->getKey();

            return is_int($key) || is_string($key) ? $key : null;
        }

        return null;
    }

    /**
     * Constraints condition the instance: rows carrying them never match
     * instance-less checks, and corrupt shapes fail closed.
     */
    private function passesConstraints(Model $permission, Model|string|null $entity, Model $authority, bool $forbidden): bool
    {
        $options = $permission->getAttribute('options');

        if ($options === null) {
            return true;
        }

        $group = \ElPandaPe\Bouncer\Constraints\ConstraintSerializer::deserialize($options);

        if (! $group instanceof \ElPandaPe\Bouncer\Constraints\Group) {
            // Undecidable constraints fail closed in each pass's safe
            // direction: a grant must not widen, a forbid must not lift.
            return $forbidden;
        }

        if (! $entity instanceof Model) {
            return false;
        }

        return $group->passes($entity, $authority);
    }

    /**
     * The authority's usable role keys for this check: unrestricted ones
     * always count; restricted ones only when the entity belongs to their
     * context (fail-closed without an instance, unless the entity IS it).
     *
     * @return list<int|string>
     */
    private function effectiveRoleKeys(Model $authority, Model|string|null $entity): array
    {
        $assignments = $this->context->assignedRoleClass()::query()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->get();

        $keys = [];

        foreach ($assignments as $assignment) {
            $contextType = $assignment->getAttribute('restricted_to_type');
            $contextId = $assignment->getAttribute('restricted_to_id');
            $roleKey = $assignment->getAttribute('role_id');

            if (! is_int($roleKey) && ! is_string($roleKey)) {
                continue; // @codeCoverageIgnore
            }

            if ($contextType === null && $contextId === null) {
                $keys[] = $roleKey;

                continue;
            }

            // A half-written restriction is not "unrestricted": fail closed.
            if ($contextType === null || $contextId === null) {
                continue;
            }

            $usable = $entity instanceof Model
                && is_string($contextType)
                && (is_int($contextId) || is_string($contextId))
                && $this->context->belongsToContext($entity, $contextType, $contextId);

            if ($usable) {
                $keys[] = $roleKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  Builder<Permission>  $builder
     */
    private function applyEntityPredicates(Builder $builder, Model|string|null $entity): void
    {
        if ($entity === null) {
            // A simple check: named permissions match the simple shape only;
            // the '*' name additionally matches the global wildcard shape.
            $builder->whereNull('entity_type')
                ->orWhere(
                    /** @param Builder<Permission> $wildcard */
                    function (Builder $wildcard): void {
                        $wildcard->where('name', '*')->where('entity_type', '*');
                    },
                );

            return;
        }

        if ($entity === '*') {
            $builder->where('entity_type', '*');

            return;
        }

        if (is_string($entity)) {
            // resolve() already abstained on non-class strings.
            assert(is_subclass_of($entity, Model::class));

            $morph = (new $entity)->getMorphClass();

            $builder->where('entity_type', '*')
                ->orWhere(
                    /** @param Builder<Permission> $blanket */
                    function (Builder $blanket) use ($morph): void {
                        $blanket->where('entity_type', $morph)->whereNull('entity_id');
                    },
                );

            return;
        }

        $morph = $entity->getMorphClass();
        $key = $entity->getKey();

        $builder->where('entity_type', '*')
            ->orWhere(
                /** @param Builder<Permission> $forModel */
                function (Builder $forModel) use ($morph, $key): void {
                    $forModel->where('entity_type', $morph)
                        ->where(
                            /** @param Builder<Permission> $scope */
                            function (Builder $scope) use ($key): void {
                                $scope->whereNull('entity_id')->orWhere('entity_id', $key);
                            },
                        );
                },
            );
    }

    /**
     * @param  list<int|string>  $roleKeys
     */
    private function applyGrantPredicates(
        QueryBuilder $builder,
        Model $authority,
        Model $permissionModel,
        array $roleKeys,
        bool $forbidden,
    ): void {
        // Derive every reference from the actual models so overrides stay in sync.
        $grants = (new ($this->context->grantClass()))->getTable();
        $permissionKey = $permissionModel->getQualifiedKeyName();
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        $filter = app(\ElPandaPe\Bouncer\Tenancy\Tenancy::class)->readFilter();

        $builder->from($grants)
            ->whereColumn("{$grants}.permission_id", $permissionKey)
            ->where("{$grants}.forbidden", $forbidden);

        // The same read filter that scopes catalog and pivots, applied to raw grants.
        if ($filter !== null && $filter[0] === 'both') {
            $tenant = $filter[1];

            $builder->where(function (QueryBuilder $q) use ($grants, $tenant): void {
                $q->whereNull("{$grants}.scope")->orWhere("{$grants}.scope", $tenant);
            });
        } elseif ($filter !== null) {
            $builder->whereNull("{$grants}.scope");
        }

        $builder
            ->where(function (QueryBuilder $grant) use ($authority, $grants, $roleMorph, $roleKeys): void {
                $grant
                    ->where(function (QueryBuilder $direct) use ($authority, $grants): void {
                        $direct->where("{$grants}.entity_type", $authority->getMorphClass())
                            ->where("{$grants}.entity_id", $authority->getKey());
                    })
                    ->orWhere(function (QueryBuilder $viaRole) use ($grants, $roleMorph, $roleKeys): void {
                        $viaRole->where("{$grants}.entity_type", $roleMorph)
                            ->whereIn("{$grants}.entity_id", $roleKeys);
                    })
                    ->orWhereNull("{$grants}.entity_id");
            });
    }
}
