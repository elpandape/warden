<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tenancy;

use ElPandaPe\Warden\Models\Concerns\IsPermission;
use ElPandaPe\Warden\Models\Concerns\IsRole;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    /**
     * An explicit null means "keep it global": only untouched rows are stamped,
     * so whoever sets the scope first wins and the order of hooks stops mattering.
     */
    public static function stampScope(Model $model, bool $catalog): void
    {
        $tenancy = app(Tenancy::class);

        if ($catalog && ! $tenancy->scopesCatalog()) {
            return;
        }

        if (! array_key_exists('scope', $model->getAttributes())) {
            $model->setAttribute('scope', $tenancy->current());
        }
    }

    protected static function bootBelongsToTenant(): void
    {
        $catalog = static::tenantCatalog();

        static::addGlobalScope(new TenantScope(catalog: $catalog));

        static::creating(fn (Model $model) => self::stampScope($model, $catalog));
    }

    protected static function tenantCatalog(): bool
    {
        $uses = class_uses_recursive(static::class);

        return in_array(IsPermission::class, $uses, true) || in_array(IsRole::class, $uses, true);
    }
}
