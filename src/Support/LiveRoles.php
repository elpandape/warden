<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use ElPandaPe\Warden\Context;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A role in the trash grants, forbids and nests nothing, yet its rows stay
 * for restore(). Only the role model's own soft deletes count: its other
 * global scopes, the catalog's tenant scope among them, never reach a check.
 *
 * @internal
 */
final class LiveRoles
{
    /**
     * Inside the statement that reads the pivot, so a check costs no extra
     * query, and only when the role model soft-deletes, so any other install
     * runs the SQL it always ran.
     */
    public static function only(Builder $pivotQuery, string $column): void
    {
        $role = new (Context::resolve()->roleClass());

        if (! array_key_exists(SoftDeletingScope::class, $role->getGlobalScopes())) {
            return;
        }

        $pivotQuery->whereIn($column, $role->newQuery()
            ->withoutGlobalScopesExcept([SoftDeletingScope::class])
            ->select($role->getQualifiedKeyName()));
    }
}
