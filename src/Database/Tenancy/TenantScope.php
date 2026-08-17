<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
final readonly class TenantScope implements Scope
{
    public function __construct(private bool $catalog) {}

    /**
     * {@inheritDoc}
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($this->catalog && ! $tenancy->scopesCatalog()) {
            return;
        }

        $filter = $tenancy->readFilter();

        if ($filter === null) {
            return;
        }

        $column = $model->qualifyColumn('scope');

        if ($filter[0] === 'both') {
            // Rows without a scope are global: visible from every tenant.
            $tenant = $filter[1];

            $builder->where(function (Builder $query) use ($column, $tenant): void {
                $query->whereNull($column)->orWhere($column, $tenant);
            });

            return;
        }

        $builder->whereNull($column);
    }
}
