<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles;

final class RoleTitle
{
    public static function generate(string $name): string
    {
        return Words::humanize($name);
    }
}
