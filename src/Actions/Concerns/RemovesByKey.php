<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * What a removal needs to announce exactly the rows it took.
 */
trait RemovesByKey
{
    use ValidatesModels;

    /**
     * The models a call named, keyed by their key, in the order it named them.
     * A name places every model found under it; a model the engine's collation
     * matched beyond the exact spelling follows the rest.
     *
     * @param  list<string|Model>  $asked
     * @param  list<Model>  $found
     * @return array<int|string, Model>
     */
    private function inRequestOrder(array $asked, array $found): array
    {
        $ordered = [];

        foreach ($asked as $item) {
            foreach ($found as $model) {
                if ($model === $item || $model->getAttribute('name') === $item) {
                    $ordered[$this->modelKey($model)] ??= $model;
                }
            }
        }

        foreach ($found as $model) {
            $ordered[$this->modelKey($model)] ??= $model;
        }

        return $ordered;
    }

    /**
     * One delete per primary key, through the base query builder: a row is
     * only announced by the call whose delete removed it, however many race
     * for it.
     *
     * @template TRow of Model
     *
     * @param  iterable<TRow>  $rows
     * @return list<TRow>
     */
    private function deleteByKey(iterable $rows): array
    {
        $deleted = [];

        foreach ($rows as $row) {
            if ($row->newModelQuery()->toBase()->where($row->getKeyName(), $row->getKey())->delete() === 1) {
                $deleted[] = $row;
            }
        }

        return $deleted;
    }
}
