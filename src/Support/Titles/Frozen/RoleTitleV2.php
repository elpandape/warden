<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles\Frozen;

use Illuminate\Support\Str;

/**
 * Warden's role titles as they stood in 2.0.0, transcribed and frozen: camel
 * case split by running Str::snake() over the whole name, namespaces included.
 */
final class RoleTitleV2
{
    public static function generate(string $name): string
    {
        return Str::ucfirst(str_replace(['-', '_'], ' ', Str::snake($name, ' ')));
    }
}
