<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One reading of the trash for every catalog hook: whether a delete sends
 * the row there decides both the softDeleted flag and whether the cascade
 * sweeps, and the two must never disagree.
 *
 * @internal
 */
final class Trash
{
    /**
     * Model::delete() fires the delete events for a soft delete too, yet the
     * row stays and nothing cascades. A force delete, out of the trash
     * included, sends nothing there.
     */
    public static function receives(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting() === false;
    }

    /**
     * Whether the stored row sits in the trash. Read from the last value the
     * model loaded or saved, not from its in-memory attribute: restore() nulls
     * that attribute before save(), so a retry or a vetoed save would else
     * read the outcome it is trying to reach. A model that no longer exists
     * holds nothing, whatever it still carries.
     */
    public static function holds(Model $model): bool
    {
        $column = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

        if (! $model->exists || ! is_string($column)) {
            return false;
        }

        $stored = $model->getRawOriginal();

        if (array_key_exists($column, $stored)) {
            return $stored[$column] !== null;
        }

        return $model->newQueryWithoutScopes()->whereKey($model->getKey())->whereNotNull($model->qualifyColumn($column))->exists();
    }

    /**
     * Whether a restore's save actually reached the database. Eloquent nulls
     * the deleted-at attribute before calling save(); a vetoed save leaves it
     * dirty, because syncing the original only happens once save() succeeds.
     * Laravel before 13.18 fires `restored` whatever save() returned.
     */
    public static function restoreWasSaved(Model $model): bool
    {
        $column = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

        return is_string($column) && ! $model->isDirty($column);
    }
}
