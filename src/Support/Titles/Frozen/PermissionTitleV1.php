<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Titles\Frozen;

use Illuminate\Support\Str;

/**
 * Warden's permission titles as they stood from 1.0.0 to 1.3.0, transcribed
 * and frozen.
 *
 * A row titled under that generator keeps its title through every upgrade, so
 * the only way to recognise one later is to have written the old rule down.
 * This class never tracks the live generator again: reconstructing an old
 * title from code that still moves breaks the day the live one changes shape.
 */
final class PermissionTitleV1
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
        return $name === '*' ? 'Manage' : Str::ucfirst(str_replace(['-', '_'], ' ', $name));
    }

    private static function entity(string $entityType): string
    {
        $basename = Str::afterLast(Str::afterLast($entityType, '\\'), '.');

        return Str::lower(Str::snake($basename, ' '));
    }
}
