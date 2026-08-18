<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Constraints;

use ElPandaPe\Bouncer\Contracts\Constraint;
use ElPandaPe\Bouncer\Enums\ComparisonOperator;
use ElPandaPe\Bouncer\Enums\ConstraintType;
use Illuminate\Database\Eloquent\Model;

/**
 * Compares an attribute of the checked entity against one of the authority.
 */
final readonly class ColumnConstraint implements Constraint
{
    public function __construct(
        public string $column,
        public ComparisonOperator $operator,
        public string $authorityColumn,
    ) {}

    public function passes(Model $entity, ?Model $authority): bool
    {
        // Everyone-grants have no authority to compare against: fail closed.
        if (! $authority instanceof Model) {
            return false;
        }

        return $this->operator->compare(
            $entity->getAttribute($this->column),
            $authority->getAttribute($this->authorityColumn),
        );
    }

    public function toArray(): array
    {
        return [
            't' => ConstraintType::Column->value,
            'c' => $this->column,
            'o' => $this->operator->value,
            'a' => $this->authorityColumn,
        ];
    }
}
