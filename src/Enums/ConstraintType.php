<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

/**
 * The serialized discriminator: an enum, never a class name (C3).
 */
enum ConstraintType: string
{
    case Value = 'value';
    case Column = 'column';
    case Group = 'group';
}
