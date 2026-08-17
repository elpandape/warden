<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Database\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Database\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        $catalog = static::tenantCatalog();

        static::addGlobalScope(new TenantScope(catalog: $catalog));

        static::creating(function (Model $model) use ($catalog): void {
            $tenancy = app(Tenancy::class);

            if ($catalog && ! $tenancy->scopesCatalog()) {
                return;
            }

            // An explicit null means "keep it global": only stamp untouched rows.
            if (! array_key_exists('scope', $model->getAttributes())) {
                $model->setAttribute('scope', $tenancy->current());
            }
        });
    }

    protected static function tenantCatalog(): bool
    {
        $uses = class_uses_recursive(static::class);

        return in_array(IsPermission::class, $uses, true) || in_array(IsRole::class, $uses, true);
    }
}
