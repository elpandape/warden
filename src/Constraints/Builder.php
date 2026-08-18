<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Constraints;

use Closure;
use ElPandaPe\Bouncer\Contracts\Constraint;
use ElPandaPe\Bouncer\Enums\ComparisonOperator;
use ElPandaPe\Bouncer\Enums\LogicalOperator;
use ElPandaPe\Bouncer\Exceptions\ConfigurationException;

/**
 * The fluent surface behind allow(...)->to(...)->where(...): Eloquent-shaped
 * on purpose, so the grammar your queries already use grants permissions too.
 */
final class Builder
{
    /** @var list<array{0: LogicalOperator, 1: Constraint}> */
    private array $items = [];

    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add(LogicalOperator::And, $column, $operator, $value, func_num_args());
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add(LogicalOperator::Or, $column, $operator, $value, func_num_args());
    }

    public function whereColumn(string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn = null): static
    {
        return $this->addColumn(LogicalOperator::And, $column, $operatorOrAuthorityColumn, $authorityColumn);
    }

    public function orWhereColumn(string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn = null): static
    {
        return $this->addColumn(LogicalOperator::Or, $column, $operatorOrAuthorityColumn, $authorityColumn);
    }

    public function group(): Group
    {
        return new Group($this->items);
    }

    private function add(LogicalOperator $logic, string|Closure $column, mixed $operator, mixed $value, int $arguments): static
    {
        if ($column instanceof Closure) {
            $nested = new self;
            $column($nested);
            $this->items[] = [$logic, $nested->group()];

            return $this;
        }

        // Two arguments mean equality, like Eloquent: where('status', 'live').
        [$comparison, $compared] = $arguments <= 2
            ? [ComparisonOperator::Equal, $operator]
            : [$this->operator($operator), $value];

        $this->items[] = [$logic, new ValueConstraint($column, $comparison, $compared)];

        return $this;
    }

    private function addColumn(LogicalOperator $logic, string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn): static
    {
        [$comparison, $against] = $authorityColumn === null
            ? [ComparisonOperator::Equal, $operatorOrAuthorityColumn]
            : [$this->operator($operatorOrAuthorityColumn), $authorityColumn];

        $this->items[] = [$logic, new ColumnConstraint($column, $comparison, $against)];

        return $this;
    }

    private function operator(mixed $operator): ComparisonOperator
    {
        $comparison = is_string($operator) ? ComparisonOperator::tryFrom($operator) : null;

        return $comparison ?? throw new ConfigurationException(
            'Unsupported constraint operator '.(is_string($operator) ? "[{$operator}]" : 'type').'.',
        );
    }
}
