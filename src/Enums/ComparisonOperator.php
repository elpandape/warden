<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Enums;

enum ComparisonOperator: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case LessThan = '<';
    case GreaterThan = '>';
    case LessThanOrEqual = '<=';
    case GreaterThanOrEqual = '>=';

    public function compare(mixed $left, mixed $right): bool
    {
        return match ($this) {
            self::Equal => $left == $right,
            self::NotEqual => $left != $right,
            self::LessThan => $left < $right,
            self::GreaterThan => $left > $right,
            self::LessThanOrEqual => $left <= $right,
            self::GreaterThanOrEqual => $left >= $right,
        };
    }
}
