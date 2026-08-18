<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use BackedEnum;

final class Name
{
    /**
     * Permission and role names accept backed enums anywhere a string works.
     */
    public static function of(BackedEnum|string $name): string
    {
        return is_string($name) ? $name : (string) $name->value;
    }
}
