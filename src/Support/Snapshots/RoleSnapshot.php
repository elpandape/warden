<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Snapshots;

use Illuminate\Database\Eloquent\Model;

/**
 * A catalog role as an array a listener can store and compare. Version 1 is
 * frozen: a new shape is a new version, never an edit.
 *
 * @phpstan-type RoleShape array{v: 1, key: int|string|null, name: string, title: string|null, scope: int|string|null}
 */
final class RoleSnapshot
{
    public const int VERSION = 1;

    /**
     * @return RoleShape
     */
    public static function of(Model $role): array
    {
        $name = $role->getAttribute('name');
        $title = $role->getAttribute('title');

        return [
            'v' => self::VERSION,
            'key' => self::identifier($role->getKey()),
            'name' => is_string($name) ? $name : '',
            'title' => is_string($title) ? $title : null,
            'scope' => self::identifier($role->getAttribute('scope')),
        ];
    }

    private static function identifier(mixed $value): int|string|null
    {
        if (is_string($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }
}
