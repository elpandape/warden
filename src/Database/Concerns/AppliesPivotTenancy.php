<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Database\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Expression;

trait AppliesPivotTenancy
{
    /**
     * Pivot joins do not inherit the model's global scope: filter them here.
     *
     * @template TRelated of Model
     * @template TDeclaring of Model
     * @template TPivot of \Illuminate\Database\Eloquent\Relations\MorphPivot
     *
     * @param  MorphToMany<TRelated, TDeclaring, TPivot>  $relation
     * @return MorphToMany<TRelated, TDeclaring, TPivot>
     */
    protected function applyPivotTenancy(MorphToMany $relation, string $table): MorphToMany
    {
        $filter = app(Tenancy::class)->readFilter();

        if ($filter === null) {
            return $relation;
        }

        $column = $relation->getBaseQuery()->getGrammar()->wrap("{$table}.scope");

        if ($filter[0] === 'both') {
            // Grouped raw predicate: the identifier is grammar-wrapped, safe by construction.
            /** @phpstan-ignore argument.type */
            return $relation->whereRaw(new Expression("({$column} is null or {$column} = ?)"), [$filter[1]]);
        }

        /** @phpstan-ignore argument.type */
        return $relation->whereRaw(new Expression("{$column} is null"));
    }
}
