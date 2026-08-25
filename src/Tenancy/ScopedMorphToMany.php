<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder;

/**
 * Reads fall back to global rows, writes target one exact scope. Laravel
 * rebuilds a bare query for every pivot write and the relation's own where
 * clauses do not survive that rebuild, so detach, sync, toggle and
 * updateExistingPivot would otherwise reach every scope at once.
 *
 * @template TRelated of Model
 * @template TDeclaring of Model
 * @template TPivot of MorphPivot
 *
 * @extends MorphToMany<TRelated, TDeclaring, TPivot>
 */
final class ScopedMorphToMany extends MorphToMany
{
    private int|string|null $writeScope = null;

    /**
     * @return ScopedMorphToMany<TRelated, TDeclaring, TPivot>
     */
    public function writingWithin(int|string|null $scope): self
    {
        $this->writeScope = $scope;
        $this->pivotValues[] = ['column' => 'scope', 'value' => $scope];

        return $this;
    }

    public function newPivotQuery(): Builder
    {
        $query = parent::newPivotQuery();

        return $this->writeScope === null
            ? $query->whereNull("{$this->table}.scope")
            : $query->where("{$this->table}.scope", $this->writeScope);
    }
}
