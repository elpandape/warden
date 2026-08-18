<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Constraints;

use ElPandaPe\Bouncer\Contracts\Constraint;
use ElPandaPe\Bouncer\Enums\ComparisonOperator;
use ElPandaPe\Bouncer\Enums\ConstraintType;
use ElPandaPe\Bouncer\Enums\LogicalOperator;

/**
 * The persisted shape is versioned and discriminated by enum, never by class
 * name (C3). Anything structurally off deserializes to null, and the
 * resolvers treat null-from-non-null as "this row never matches": corrupt
 * constraints fail closed, they do not widen a grant.
 */
final class ConstraintSerializer
{
    public const int VERSION = 1;

    /**
     * @return array{v: int, g: array<string, mixed>}
     */
    public static function serialize(Group $group): array
    {
        return ['v' => self::VERSION, 'g' => $group->toArray()];
    }

    public static function deserialize(mixed $options): ?Group
    {
        if (is_string($options)) {
            if (! json_validate($options)) {
                return null;
            }

            $options = json_decode($options, true);
        }

        if (! is_array($options) || ($options['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $group = self::constraint($options['g'] ?? null);

        return $group instanceof Group ? $group : null;
    }

    private static function constraint(mixed $shape): ?Constraint
    {
        if (! is_array($shape)) {
            return null;
        }

        $type = is_string($shape['t'] ?? null) ? ConstraintType::tryFrom($shape['t']) : null;

        return match ($type) {
            ConstraintType::Value => self::value($shape),
            ConstraintType::Column => self::column($shape),
            ConstraintType::Group => self::group($shape),
            null => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $shape
     */
    private static function value(array $shape): ?ValueConstraint
    {
        $column = $shape['c'] ?? null;
        $operator = is_string($shape['o'] ?? null) ? ComparisonOperator::tryFrom($shape['o']) : null;

        if (! is_string($column) || $operator === null || ! array_key_exists('v', $shape)) {
            return null;
        }

        return new ValueConstraint($column, $operator, $shape['v']);
    }

    /**
     * @param  array<array-key, mixed>  $shape
     */
    private static function column(array $shape): ?ColumnConstraint
    {
        $column = $shape['c'] ?? null;
        $operator = is_string($shape['o'] ?? null) ? ComparisonOperator::tryFrom($shape['o']) : null;
        $authorityColumn = $shape['a'] ?? null;

        if (! is_string($column) || $operator === null || ! is_string($authorityColumn)) {
            return null;
        }

        return new ColumnConstraint($column, $operator, $authorityColumn);
    }

    /**
     * @param  array<array-key, mixed>  $shape
     */
    private static function group(array $shape): ?Group
    {
        $items = $shape['i'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        $constraints = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_keys($item) !== [0, 1]) {
                return null;
            }

            $logic = is_string($item[0]) ? LogicalOperator::tryFrom($item[0]) : null;
            $constraint = self::constraint($item[1]);

            if ($logic === null || $logic === LogicalOperator::Not || ! $constraint instanceof Constraint) {
                return null;
            }

            $constraints[] = [$logic, $constraint];
        }

        return new Group($constraints);
    }
}
