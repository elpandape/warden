<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Exceptions\TrashedCatalogRow;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
        if (app(Tenancy::class)->current() === null) {
            return $query->whereNull('scope');
        }

        // The read scope pairs global + tenant, and firstOrCreate takes whichever
        // row the engine hands back first — which nothing ordered, so it differed
        // per engine. A row the tenant minted for itself wins the one it shadows.
        return $query->orderByRaw('scope is null');
    }

    /**
     * firstOrCreate, except that it never mints a namesake of a row in the
     * trash: the lookup cannot see that row, and the insert would collide with
     * it or, under a null scope, sit beside it until a restore() made two.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function firstOrCreateLive(Builder $query, array $attributes): Model
    {
        $found = (clone $query)->where($attributes)->first();

        if ($found !== null) {
            return $found;
        }

        $model = $query->getModel();
        $trash = $this->trashColumn($model);
        $tenancy = app(Tenancy::class);

        // Only the row the insert would stand in for counts: a tenant may mint
        // its own row over a trashed global one, as it may over a live one.
        $trashed = $trash !== null && (clone $query)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where($attributes)
            ->where($model->qualifyColumn('scope'), $tenancy->scopesCatalog() ? $tenancy->current() : null)
            ->whereNotNull($trash)
            ->exists();

        if ($trashed) {
            throw $this->trashedNamesake($model, $attributes);
        }

        return $query->createOrFirst($attributes);
    }

    /**
     * The column a catalog model keeps its trash in, or null when it keeps
     * none, so a model without SoftDeletes pays for no extra lookup.
     */
    private function trashColumn(Model $model): ?string
    {
        $column = method_exists($model, 'getQualifiedDeletedAtColumn') ? $model->getQualifiedDeletedAtColumn() : null;

        return $model::hasGlobalScope(SoftDeletingScope::class) && is_string($column) ? $column : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function trashedNamesake(Model $model, array $attributes): TrashedCatalogRow
    {
        $name = $attributes['name'] ?? null;
        $name = is_string($name) ? $name : '';

        if ($model instanceof (Context::resolve()->roleClass())) {
            return TrashedCatalogRow::role($name);
        }

        $type = $attributes['entity_type'] ?? null;
        $id = $attributes['entity_id'] ?? null;

        return TrashedCatalogRow::permission(
            $name,
            match (true) {
                ! is_string($type) => null,
                is_int($id) || is_string($id) => $type.':'.$id,
                default => $type,
            },
            onlyOwned: (bool) ($attributes['only_owned'] ?? false),
            withConditions: ($attributes['options'] ?? null) !== null,
        );
    }
}
