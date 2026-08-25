<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Expression;

trait AppliesPivotTenancy
{
    /**
     * Warden builds the relation itself so pivot writes stay inside one scope;
     * morphToMany() would hand back a relation that cannot express that.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return ScopedMorphToMany<TRelated, $this, \Illuminate\Database\Eloquent\Relations\MorphPivot>
     */
    protected function scopedMorphToMany(
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $relationName,
        bool $inverse,
        bool $roleGrant,
    ): ScopedMorphToMany {
        $instance = $this->newRelatedInstance($related);

        /** @var ScopedMorphToMany<TRelated, $this, \Illuminate\Database\Eloquent\Relations\MorphPivot> $relation */
        $relation = new ScopedMorphToMany(
            $instance->newQuery(),
            $this,
            'entity',
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKeyName(),
            $instance->getKeyName(),
            $relationName,
            $inverse,
        );

        return $relation->writingWithin(
            app(Tenancy::class)->writeScope(forRoleGrant: $roleGrant),
        );
    }

    /**
     * Pivot joins do not inherit the model's global scope: filter them here.
     *
     * @template TRelatedModel of Model
     * @template TDeclaring of Model
     * @template TPivot of \Illuminate\Database\Eloquent\Relations\MorphPivot
     *
     * @param  MorphToMany<TRelatedModel, TDeclaring, TPivot>  $relation
     * @return MorphToMany<TRelatedModel, TDeclaring, TPivot>
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
