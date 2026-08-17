<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Titles;

use Illuminate\Support\Str;

final class RoleTitle
{
    public static function generate(string $name): string
    {
        return Str::ucfirst(str_replace(['-', '_'], ' ', $name));
    }
}
