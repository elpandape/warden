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

    /**
     * Narrowed by scope, and by scope only: no predicate on restricted_to_*.
     * That is deliberate, and it mirrors retract()->from() without on(), which
     * deletes restricted assignments the same way — the relation is not
     * narrower than the verb it reflects. To remove one context and leave the
     * others, name it: Warden::retract($role)->on($context)->from($authority).
     */
    public function newPivotQuery(): Builder
    {
        $query = parent::newPivotQuery();

        return $this->writeScope === null
            ? $query->whereNull("{$this->table}.scope")
            : $query->where("{$this->table}.scope", $this->writeScope);
    }
}
