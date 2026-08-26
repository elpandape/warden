<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles;

final class RoleTitle
{
    public static function generate(string $name): string
    {
        return Words::humanize($name);
    }

    /**
     * Every title Warden could have written for this name, current first.
     *
     * @return list<string>
     */
    public static function generations(string $name): array
    {
        return array_values(array_unique([
            self::generate($name),
            Frozen\RoleTitleV2::generate($name),
            Frozen\RoleTitleV1::generate($name),
        ]));
    }
}
