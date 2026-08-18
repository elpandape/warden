<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ConstrainsCatalogLookups
{
    /**
     * With no active tenant, creation lookups reuse only global rows: a
     * same-named row inside some tenant must not absorb a global write.
     * Under a tenant, the catalog read scope already pairs global + tenant.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function constrainCatalogLookup(Builder $query): Builder
    {
        return app(Tenancy::class)->current() === null
            ? $query->whereNull('scope')
            : $query;
    }
}
