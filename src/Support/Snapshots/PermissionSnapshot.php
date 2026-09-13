<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support\Snapshots;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalog permission as an array a listener can store, compare and read
 * back. Version 1 is frozen: a new shape is a new version, never an edit.
 *
 * @phpstan-type PermissionShape array{v: 1, key: int|string|null, name: string, title: string|null, entity_type: string|null, entity_id: int|string|null, only_owned: bool, scope: int|string|null, conditions: array<string, mixed>|null}
 */
final class PermissionSnapshot
{
    public const int VERSION = 1;

    /**
     * @return PermissionShape
     */
    public static function of(Model $permission): array
    {
        $name = $permission->getAttribute('name');
        $title = $permission->getAttribute('title');
        $entityType = $permission->getAttribute('entity_type');

        return [
            'v' => self::VERSION,
            'key' => self::identifier($permission->getKey()),
            'name' => is_string($name) ? $name : '',
            'title' => is_string($title) ? $title : null,
            'entity_type' => is_string($entityType) ? $entityType : null,
            'entity_id' => self::identifier($permission->getAttribute('entity_id')),
            'only_owned' => (bool) $permission->getAttribute('only_owned'),
            'scope' => self::identifier($permission->getAttribute('scope')),
            // Ask the column, not the cast: an undecodable blob casts to null.
            'conditions' => self::conditions($permission->getAttributes()['options'] ?? null),
        ];
    }

    private static function identifier(mixed $value): int|string|null
    {
        if (is_string($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function conditions(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        // Judge what is stored, as the resolvers do: a rule encoded twice decodes
        // once into text, and no resolver reads text as a rule.
        if (! ConstraintSerializer::deserialize($stored) instanceof Group) {
            return ['unreadable' => is_string($stored) ? $stored : json_encode($stored, JSON_THROW_ON_ERROR)];
        }

        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        /** @var array<string, mixed> $canonical */
        $canonical = ConstraintSerializer::canonicalOf($decoded);

        return $canonical;
    }
}
