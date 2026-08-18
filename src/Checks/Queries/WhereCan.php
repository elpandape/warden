<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks\Queries;

use Closure;
use ElPandaPe\Bouncer\Constraints\ColumnConstraint;
use ElPandaPe\Bouncer\Constraints\ConstraintSerializer;
use ElPandaPe\Bouncer\Constraints\Group;
use ElPandaPe\Bouncer\Constraints\ValueConstraint;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Contracts\Constraint;
use ElPandaPe\Bouncer\Enums\LogicalOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Answers "over WHICH rows can X do Y" — the question the data model makes
 * possible: grant shapes and constraints compile into row conditions, while
 * grant existence resolves once at build time.
 *
 * Scope: ownership compiles for attribute-resolved classes (closures cannot
 * become SQL) and restricted-role assignments are excluded — both fail
 * closed. Constraint comparisons inside the query use the database engine's
 * own semantics.
 */
final readonly class WhereCan
{
    public function __construct(private Context $context) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, Model $authority, string $permission): Builder
    {
        $model = $query->getModel();

        $candidates = $this->candidates($model, $permission);
        $granted = $this->activeKeys($authority, $candidates, forbidden: false);
        $forbidden = $this->activeKeys($authority, $candidates, forbidden: true);

        $grantBranches = [];
        $forbidBranches = [];

        foreach ($candidates as $candidate) {
            $rawKey = $candidate->getKey();
            $key = is_int($rawKey) || is_string($rawKey) ? (string) $rawKey : '';

            if (in_array($key, $granted, true)) {
                $branch = $this->branch($candidate, $model, $authority, blocking: false);

                if ($branch instanceof Closure) {
                    $grantBranches[] = $branch;
                }
            }

            if (in_array($key, $forbidden, true)) {
                $branch = $this->branch($candidate, $model, $authority, blocking: true);

                if ($branch instanceof Closure) {
                    $forbidBranches[] = $branch;
                }
            }
        }

        if ($grantBranches === []) {
            // Nothing grants this permission over the model: an empty result.
            return $query->whereIn($model->getQualifiedKeyName(), []);
        }

        return $query->where(function (Builder $outer) use ($grantBranches, $forbidBranches): void {
            $outer->where(function (Builder $any) use ($grantBranches): void {
                foreach ($grantBranches as $branch) {
                    $any->orWhere($branch);
                }
            });

            // SQL null semantics work in our favor here: a NOT over an
            // indeterminate forbid condition still excludes the row.
            if ($forbidBranches !== []) {
                $outer->whereNot(function (Builder $none) use ($forbidBranches): void {
                    foreach ($forbidBranches as $branch) {
                        $none->orWhere($branch);
                    }
                });
            }
        });
    }

    /**
     * Catalog rows that could apply to instances of this model, under the
     * current tenant filter.
     *
     * @return Collection<int, Model>
     */
    private function candidates(Model $model, string $permission): Collection
    {
        /** @var Collection<int, Model> */
        return $this->context->permissionClass()::query()
            ->whereIn('name', [$permission, '*'])
            ->where(function (Builder $query) use ($model): void {
                $query->where('entity_type', '*')
                    ->orWhere('entity_type', $model->getMorphClass());
            })
            ->get()
            ->toBase();
    }

    /**
     * Which candidates actually reach the authority (directly, through an
     * unrestricted role, or as everyone-grants) — resolved once, not per row.
     *
     * @param  Collection<int, Model>  $candidates
     * @return list<string>
     */
    private function activeKeys(Model $authority, Collection $candidates, bool $forbidden): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        // Restricted assignments cannot compile into row conditions. Each side
        // fails closed its own way: the GRANT pass excludes them (a restricted
        // editor is not a queryable global editor), while the FORBID pass
        // includes them — over-blocking beats returning a row can() denies.
        $assignments = $this->context->assignedRoleClass()::query()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey());

        if (! $forbidden) {
            $assignments->whereNull('restricted_to_type')->whereNull('restricted_to_id');
        }

        $roleKeys = $assignments
            ->toBase()
            ->pluck('role_id')
            ->all();

        $active = $this->context->grantClass()::query()
            ->whereIn('permission_id', $candidates->map(fn (Model $candidate): mixed => $candidate->getKey())->all())
            ->where('forbidden', $forbidden)
            ->where(function (Builder $query) use ($authority, $roleMorph, $roleKeys): void {
                $query
                    ->where(function (Builder $direct) use ($authority): void {
                        $direct->where('entity_type', $authority->getMorphClass())
                            ->where('entity_id', $authority->getKey());
                    })
                    ->orWhere(function (Builder $viaRole) use ($roleMorph, $roleKeys): void {
                        $viaRole->where('entity_type', $roleMorph)
                            ->whereIn('entity_id', $roleKeys);
                    })
                    ->orWhereNull('entity_id');
            })
            ->toBase()
            ->pluck('permission_id');

        $keys = [];

        foreach ($active as $key) {
            if (is_int($key) || is_string($key)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * The row conditions one candidate imposes, or null when the candidate
     * cannot be expressed for this side: an inexpressible grant is skipped
     * (fail-closed), an inexpressible forbid blocks every shape-matching row.
     */
    private function branch(Model $candidate, Model $model, Model $authority, bool $blocking): ?Closure
    {
        $conditions = [];

        // Shape: a specific entity_id pins the row; null covers the class.
        $entityId = $candidate->getAttribute('entity_id');

        if ($entityId !== null && $candidate->getAttribute('entity_type') !== '*') {
            $conditions[] = fn (Builder $query): Builder => $query->whereKey($entityId);
        }

        if ((bool) $candidate->getAttribute('only_owned')) {
            $attribute = $this->ownershipAttribute($model);

            if ($attribute === null) {
                // Closure-resolved ownership cannot become SQL.
                return $blocking ? $this->always() : null;
            }

            $key = $authority->getKey();

            if (! is_int($key) && ! is_string($key)) {
                return $blocking ? $this->always() : null; // @codeCoverageIgnore
            }

            $conditions[] = fn (Builder $query): Builder => $query->where($model->qualifyColumn($attribute), $key);
        }

        $options = $candidate->getAttribute('options');

        if ($options !== null) {
            $group = ConstraintSerializer::deserialize($options);

            if (! $group instanceof Group) {
                // Undecidable constraints: same doctrine as the resolvers.
                return $blocking ? $this->always() : null;
            }

            $conditions[] = fn (Builder $query): Builder => $this->compile($query, $group, $model, $authority);
        }

        if ($conditions === []) {
            return $this->always();
        }

        return function (Builder $query) use ($conditions): void {
            foreach ($conditions as $condition) {
                $query->where(fn (Builder $nested): Builder => $condition($nested));
            }
        };
    }

    private function always(): Closure
    {
        // A real tautology: Laravel silently drops empty nested wheres, so a
        // branch with no extra row conditions must still emit a predicate.
        return function (Builder $query): void {
            $query->whereRaw('1 = 1');
        };
    }

    /**
     * Constraint groups compile to nested wheres with the same precedence
     * they evaluate with in memory: AND binds tighter than OR.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function compile(Builder $query, Group $group, Model $model, Model $authority): Builder
    {
        // An empty group passes trivially in memory; emit a real tautology so
        // the builder cannot silently drop the branch (a vanishing forbid
        // would fail open).
        if ($group->items === []) {
            return $query->whereRaw('1 = 1');
        }

        $first = true;

        foreach ($group->items as [$logic, $constraint]) {
            $or = ! $first && $logic === LogicalOperator::Or;
            $apply = fn (Builder $nested): Builder => $this->compileOne($nested, $constraint, $model, $authority);

            $or
                ? $query->orWhere(fn (Builder $nested): Builder => $apply($nested))
                : $query->where(fn (Builder $nested): Builder => $apply($nested));

            $first = false;
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function compileOne(Builder $query, Constraint $constraint, Model $model, Model $authority): Builder
    {
        if ($constraint instanceof Group) {
            return $this->compile($query, $constraint, $model, $authority);
        }

        if ($constraint instanceof ValueConstraint) {
            // The strict comparator only matches booleans through a boolean
            // cast; without one the engine's coercion would fail open.
            $bool = is_bool($constraint->value)
                && ! $model->hasCast($constraint->column, ['bool', 'boolean']);

            if ($bool) {
                return $query->whereIn($model->getQualifiedKeyName(), []);
            }

            return $query->where($model->qualifyColumn($constraint->column), $constraint->operator->value, $constraint->value);
        }

        if ($constraint instanceof ColumnConstraint) {
            $value = $this->context->attributeValue($authority, $constraint->authorityColumn);

            if ($value === null) {
                // Unreadable authority attribute: an impossible condition.
                return $query->whereIn($model->getQualifiedKeyName(), []);
            }

            return $query->where($model->qualifyColumn($constraint->column), $constraint->operator->value, $value);
        }

        // Unknown constraint types cannot compile: impossible condition.
        return $query->whereIn($model->getQualifiedKeyName(), []); // @codeCoverageIgnore
    }

    /**
     * The ownership attribute for this model, or null when a closure decides.
     */
    private function ownershipAttribute(Model $model): ?string
    {
        $resolver = $this->context->ownershipResolverFor($model);

        return is_string($resolver) ? $resolver : null;
    }
}
