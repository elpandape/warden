<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Constraints;

use ElPandaPe\Warden\Contracts\Constraint;
use ElPandaPe\Warden\Enums\ConstraintType;
use ElPandaPe\Warden\Enums\LogicalOperator;
use Illuminate\Database\Eloquent\Model;

/**
 * An ordered sequence of constraints with SQL-style precedence: AND binds
 * tighter than OR, so `A or B and C` reads `A or (B and C)` — exactly like
 * Eloquent. Nest a closure in the builder for explicit grouping.
 */
final readonly class Group implements Constraint
{
    /**
     * @param  list<array{0: LogicalOperator, 1: Constraint}>  $items
     */
    public function __construct(public array $items) {}

    public function passes(Model $entity, ?Model $authority): bool
    {
        if ($this->items === []) {
            return true;
        }

        // A disjunction of conjunctions, short-circuiting per clause.
        $clause = true;
        $first = true;

        foreach ($this->items as [$logic, $constraint]) {
            if (! $first && $logic === LogicalOperator::Or) {
                if ($clause) {
                    return true;
                }

                $clause = true;
            }

            $clause = $clause && $constraint->passes($entity, $authority);
            $first = false;
        }

        return $clause;
    }

    public function toArray(): array
    {
        return [
            't' => ConstraintType::Group->value,
            'i' => array_map(
                fn (array $item): array => [$item[0]->value, $item[1]->toArray()],
                $this->items,
            ),
        ];
    }
}
