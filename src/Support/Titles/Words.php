<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles;

use Illuminate\Support\Str;

final class Words
{
    /**
     * A name carrying anything beyond letters, digits, hyphen and underscore was
     * shaped by a consumer warden knows nothing about — a namespace, a prefix —
     * so it travels whole rather than being read as camel case.
     */
    public static function humanize(string $name): string
    {
        $simple = preg_match('/^[\p{L}\p{N}_-]+$/u', $name) === 1;

        return Str::ucfirst(str_replace(['-', '_'], ' ', $simple ? Str::snake($name, ' ') : $name));
    }
}
