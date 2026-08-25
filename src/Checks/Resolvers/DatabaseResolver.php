<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Resolvers;

use ElPandaPe\Warden\Checks\Verdict;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        [$forbiddenBy, , $forbiddenRow] = $this->firstMatch($authority, $permission, $entity, $owned, $roleKeys, forbidden: true);

        if ($forbiddenBy !== null) {
            return Verdict::forbidden($forbiddenBy, $forbiddenRow);
        }

        [$grantedBy, $rejected, $grantedRow, $rejectedRow] = $this->firstMatch($authority, $permission, $entity, $owned, $roleKeys, forbidden: false);

        return $grantedBy === null
            ? Verdict::abstained($rejected, $rejectedRow)
            : Verdict::granted($grantedBy, $grantedRow);
    }

    /**
     * The first candidate a condition did not turn down, and the keys of the
     * ones it did: "no row matched" and "a row matched but its condition
     * failed" are different diagnoses.
     *
     * @param  list<int|string>  $roleKeys
     * @return array{0: int|string|null, 1: list<int|string>, 2: ?Model, 3: ?Model}
     */
    private function firstMatch(
        Model $authority,
        string $permission,
        Model|string|null $entity,
        bool $owned,
        array $roleKeys,
        bool $forbidden,
    ): array {
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
            ->whereExists($this->grantsHeld($authority, $permissionModel, $roleKeys, $forbidden))
            ->orderByRaw('entity_id is not null desc, entity_type is not null desc');

        // Resolve through Eloquent so global scopes on custom models keep
        // applying; constraints evaluate per candidate, in specificity order.
        /** @var list<int|string> $rejected */
        $rejected = [];
        $rejectedRow = null;

        foreach ($query->get() as $candidate) {
            $key = $candidate->getKey();

            if (! is_int($key) && ! is_string($key)) {
                continue; // @codeCoverageIgnore
            }

            if (! $this->passesConstraints($candidate, $entity, $authority, $forbidden)) {
                $rejected[] = $key;
                $rejectedRow ??= $candidate;

                continue;
            }

            return [$key, $rejected, $candidate, $rejectedRow];
        }

        return [null, $rejected, null, $rejectedRow];
    }

    /**
     * Constraints condition the instance: rows carrying them never match
     * instance-less checks, and corrupt shapes fail closed.
     */
    private function passesConstraints(Model $permission, Model|string|null $entity, Model $authority, bool $forbidden): bool
    {
        // Ask the column, not the cast: an undecodable blob casts to null,
        // which would read as "no conditions" and widen the grant.
        $options = $permission->getAttributes()['options'] ?? null;

        if ($options === null) {
            return true;
        }

        $group = \ElPandaPe\Warden\Constraints\ConstraintSerializer::deserialize($options);

        if (! $group instanceof \ElPandaPe\Warden\Constraints\Group) {
            // Undecidable constraints fail closed in each pass's safe
            // direction: a grant must not widen, a forbid must not lift.
            return $forbidden;
        }

        if (! $entity instanceof Model) {
            // Undecidable without an instance: same safe direction as above.
            return $forbidden;
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
     * The grants that answer this check, as a subquery.
     *
     * Built through Eloquent so a swapped grant model's global scopes apply —
     * including warden's own tenant scope, which this used to re-implement by
     * hand and could therefore drift from.
     *
     * @param  list<int|string>  $roleKeys
     * @return Builder<Grant>
     */
    private function grantsHeld(Model $authority, Model $permissionModel, array $roleKeys, bool $forbidden): Builder
    {
        $grantClass = $this->context->grantClass();
        $grants = (new $grantClass)->getTable();
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        return $grantClass::query()
            ->whereColumn("{$grants}.permission_id", $permissionModel->getQualifiedKeyName())
            ->where('forbidden', $forbidden)
            ->where(function (Builder $grant) use ($authority, $roleMorph, $roleKeys): void {
                $grant
                    ->where(function (Builder $direct) use ($authority): void {
                        $direct->where('entity_type', $authority->getMorphClass())
                            ->where('entity_id', $authority->getKey());
                    })
                    ->orWhere(function (Builder $viaRole) use ($roleMorph, $roleKeys): void {
                        $viaRole->where('entity_type', $roleMorph)
                            ->whereIn('entity_id', $roleKeys);
                    })
                    ->orWhereNull('entity_id');
            });
    }
}
