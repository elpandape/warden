<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles\Frozen;

use Illuminate\Support\Str;

/**
 * Warden's permission titles as they stood in 2.0.0, transcribed and frozen.
 *
 * 2.0.0 split camel case by running Str::snake() over the whole name, which
 * also mangled a name carrying a namespace. Both readings are here because a
 * catalogue written by that release still carries them.
 */
final class PermissionTitleV2
{
    public static function generate(
        string $name,
        ?string $entityType,
        int|string|null $entityId,
        bool $onlyOwned,
    ): string {
        return match (true) {
            $name === '*' && $entityType === '*' && $onlyOwned => 'Manage everything owned',
            $name === '*' && $entityType === '*' => 'All permissions',
            $name === '*' && $entityType === null => 'All simple permissions',
            $entityType === '*' && $onlyOwned => self::action($name).' everything owned',
            $entityType === '*' => self::action($name).' everything',
            $entityType !== null && $entityId !== null => self::action($name).' '.self::entity($entityType).' #'.$entityId,
            $entityType !== null && $name === '*' => 'Manage '.Str::plural(self::entity($entityType)),
            $entityType !== null => self::action($name).' '.Str::plural(self::entity($entityType)),
            default => self::action($name),
        };
    }

    private static function action(string $name): string
    {
        return $name === '*' ? 'Manage' : Str::ucfirst(str_replace(['-', '_'], ' ', Str::snake($name, ' ')));
    }

    private static function entity(string $entityType): string
    {
        $basename = Str::afterLast(Str::afterLast($entityType, '\\'), '.');

        return Str::lower(Str::snake($basename, ' '));
    }
}
