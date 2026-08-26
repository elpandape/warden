<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles\Frozen;

use Illuminate\Support\Str;

/**
 * Warden's role titles as they stood from 1.0.0 to 1.3.0, transcribed and
 * frozen. It never tracks the live generator again.
 */
final class RoleTitleV1
{
    public static function generate(string $name): string
    {
        return Str::ucfirst(str_replace(['-', '_'], ' ', $name));
    }
}
