<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

use LogicException;

enum LogicalOperator: string
{
    case And = 'and';
    case Or = 'or';
    /**
     * Reserved and unimplemented. Serializing one is refused and reading one
     * back is refused, so a stored Not fails closed rather than quietly meaning
     * And.
     *
     * The in-memory half is still open: Group::passes() reads a hand-built Not
     * as a conjunction. Closing it means letting a group report that it cannot
     * be decided, which changes a published signature and so cannot land before
     * a major. Until then, do not derive a connector list from cases(): the
     * third one is not a connector anything will store.
     */
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
