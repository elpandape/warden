<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything that identifies a catalog row except its name.
 *
 * The name stays a column of its own so it keeps comparing under the collation
 * the resolver reads it with. The rest rides here: sentinels where the column is
 * nullable, because every engine counts NULLs as distinct, and a digest of the
 * canonical options, because no database expression can reproduce it —
 * canonicalisation rewrites the leading operator at unbounded depth.
 */
final class PermissionIdentity
{
    public static function for(Model $permission): string
    {
        return self::from(
            $permission->getAttribute('entity_type'),
            $permission->getAttribute('entity_id'),
            (bool) $permission->getAttribute('only_owned'),
            $permission->getAttribute('scope'),
            // Ask the column, not the cast: an undecodable blob casts to null,
            // which would digest as "no conditions" — its plain sister's print.
            $permission->getAttributes()['options'] ?? null,
        );
    }

    public static function from(
        mixed $entityType,
        mixed $entityId,
        bool $onlyOwned,
        mixed $scope,
        mixed $options,
    ): string {
        return implode("\x1f", [
            is_string($entityType) ? '='.$entityType : '~',
            is_int($entityId) || is_string($entityId) ? '='.$entityId : '~',
            $onlyOwned ? '1' : '0',
            is_int($scope) || is_string($scope) ? '='.$scope : '~',
            self::digest($options),
        ]);
    }

    /**
     * Truncated SHA-256, never a fast hash: a crafted collision on a name the
     * application accepts from a user would land a grant on someone else's row.
     */
    private static function digest(mixed $options): string
    {
        if ($options === null) {
            return '~';
        }

        // The stored bytes, decoded here rather than trusted from a cast. What
        // canonicalises is the decoded shape: hashing the bytes would make the
        // print depend on key order, which a json column is free to rewrite.
        $decoded = is_string($options) ? json_decode($options, true) : $options;

        if (! is_array($decoded)) {
            // A rule nobody can read is not the absence of a rule. Give it a
            // print of its own so it collides with nothing, and so recomputing
            // the key repairs the row instead of failing against its sister.
            return '!'.substr(hash('sha256', is_string($options) ? $options : ''), 0, 31);
        }

        $canonical = ConstraintSerializer::canonicalOf($decoded);

        return substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 32);
    }
}
