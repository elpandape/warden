<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Resolvers;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Database\Permission;
use ElPandaPe\Bouncer\Verdict;
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

        // Forbidden always wins: check it before any grant.
        $forbiddenBy = $this->firstMatch($authority, $permission, $entity, $owned, forbidden: true);

        if ($forbiddenBy !== null) {
            return Verdict::forbidden($forbiddenBy);
        }

        $grantedBy = $this->firstMatch($authority, $permission, $entity, $owned, forbidden: false);

        return $grantedBy === null ? Verdict::abstained() : Verdict::granted($grantedBy);
    }

    private function firstMatch(
        Model $authority,
        string $permission,
        Model|string|null $entity,
        bool $owned,
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
                fn (QueryBuilder $builder) => $this->applyGrantPredicates($builder, $authority, $permissionModel, $forbidden),
            )
            ->orderByRaw('entity_id is not null desc, entity_type is not null desc');

        // Resolve through Eloquent so global scopes on custom models keep applying.
        $key = $query->first()?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
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

    private function applyGrantPredicates(
        QueryBuilder $builder,
        Model $authority,
        Model $permissionModel,
        bool $forbidden,
    ): void {
        // Derive every reference from the actual models so overrides stay in sync.
        $grants = (new ($this->context->grantClass()))->getTable();
        $permissionKey = $permissionModel->getQualifiedKeyName();
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        // toBase() keeps the tenant global scope applied on the subquery.
        $roleKeys = $this->context->assignedRoleClass()::query()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->toBase()
            ->select('role_id');

        $filter = app(\ElPandaPe\Bouncer\Database\Tenancy\Tenancy::class)->readFilter();

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
