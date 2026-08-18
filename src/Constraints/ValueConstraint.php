<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Constraints;

use ElPandaPe\Bouncer\Contracts\Constraint;
use ElPandaPe\Bouncer\Enums\ComparisonOperator;
use ElPandaPe\Bouncer\Enums\ConstraintType;
use Illuminate\Database\Eloquent\Model;

/**
 * Compares an attribute of the checked entity against a literal value.
 */
final readonly class ValueConstraint implements Constraint
{
    public function __construct(
        public string $column,
        public ComparisonOperator $operator,
        public mixed $value,
    ) {}

    public function passes(Model $entity, ?Model $authority): bool
    {
        return $this->operator->compare($entity->getAttribute($this->column), $this->value);
    }

    public function toArray(): array
    {
        return [
            't' => ConstraintType::Value->value,
            'c' => $this->column,
            'o' => $this->operator->value,
            'v' => $this->value,
        ];
    }
}
