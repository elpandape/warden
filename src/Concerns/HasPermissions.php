<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Concerns;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Tenancy\AppliesPivotTenancy;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasPermissions
{
    use AppliesPivotTenancy;

    /**
     * @return MorphToMany<\ElPandaPe\Warden\Models\Permission, $this, Grant>
     */
    public function permissions(): MorphToMany
    {
        $context = Context::resolve();

        $permission = $context->permissionClass();
        $grant = $context->grantClass();

        $relation = $this
            ->morphToMany($permission, 'entity', $context->table('grants'), relatedPivotKey: 'permission_id')
            ->using($grant)
            ->withPivot(['forbidden', 'scope']);

        $relation = $this->applyPivotTenancy($relation, $context->table('grants'));

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }
}
