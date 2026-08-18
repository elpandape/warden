<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

use LogicException;

enum LogicalOperator: string
{
    case And = 'and';
    case Or = 'or';
    case Not = 'not';

    public function combine(bool $carry, bool $operand): bool
    {
        return match ($this) {
            self::And => $carry && $operand,
            self::Or => $carry || $operand,
            // Not is unary: it negates a single result, it cannot combine two.
            self::Not => throw new LogicException('The "not" operator is unary and cannot combine operands.'),
        };
    }
}
