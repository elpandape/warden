<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

enum ComparisonOperator: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case LessThan = '<';
    case GreaterThan = '>';
    case LessThanOrEqual = '<=';
    case GreaterThanOrEqual = '>=';

    /**
     * Strict by design: identical types compare directly, numeric strings
     * bridge to their numbers (database drivers stringify), anything else
     * fails closed instead of falling into PHP type juggling.
     *
     * is_numeric(true) is false, so a boolean never matches a column the model
     * does not cast to bool. Writing that mismatch is refused since 3.0 and the
     * query compiler is symmetric, but a row stored before the refusal still
     * evaluates here, and evaluates to false — written as a forbid it never
     * fires. warden:doctor lists those rows; it does not rewrite them.
     */
    public function compare(mixed $left, mixed $right): bool
    {
        // SQL semantics for null: an unreadable or missing attribute never
        // satisfies any operator — not even "not equal". Fail closed.
        if ($left === null || $right === null) {
            return false;
        }

        $numeric = is_numeric($left) && is_numeric($right);

        return match ($this) {
            self::Equal => $left === $right || ($numeric && $left == $right),
            // Only decidable pairs may differ: incomparable types fail closed
            // instead of inverting Equal's own closed failure into a pass.
            self::NotEqual => ($numeric || gettype($left) === gettype($right))
                && ! self::Equal->compare($left, $right),
            self::LessThan => self::comparable($left, $right, $numeric) && $left < $right,
            self::GreaterThan => self::comparable($left, $right, $numeric) && $left > $right,
            self::LessThanOrEqual => self::comparable($left, $right, $numeric) && $left <= $right,
            self::GreaterThanOrEqual => self::comparable($left, $right, $numeric) && $left >= $right,
        };
    }

    private static function comparable(mixed $left, mixed $right, bool $numeric): bool
    {
        return $numeric || (is_string($left) && is_string($right));
    }
}
