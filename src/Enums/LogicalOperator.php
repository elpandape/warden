<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

enum LogicalOperator: string
{
    case And = 'and';
    case Or = 'or';
    /**
     * Reserved and unimplemented. Serializing one is refused, reading one back
     * is refused, and evaluating a hand-built group carrying it throws — it
     * never quietly means And anywhere. Do not derive a connector list from
     * cases(): the third one is not a connector anything will store.
     */
    case Not = 'not';
}
