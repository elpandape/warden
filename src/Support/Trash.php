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
}
