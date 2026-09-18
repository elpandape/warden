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
     * Whether the stored row sits in the trash. A model read without its
     * deleted-at column would say no, so the row itself is asked; a model
     * that no longer exists holds nothing, whatever it still carries.
     */
    public static function holds(Model $model): bool
    {
        $column = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

        if (! $model->exists || ! is_string($column)) {
            return false;
        }

        if (array_key_exists($column, $model->getAttributes())) {
            return method_exists($model, 'trashed') && $model->trashed() === true;
        }

        return $model->newQueryWithoutScopes()->whereKey($model->getKey())->whereNotNull($model->qualifyColumn($column))->exists();
    }
}
