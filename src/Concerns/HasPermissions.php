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
            ->scopedMorphToMany($permission, $context->table('grants'), 'entity_id', 'permission_id', 'permissions', inverse: false, roleGrant: $this instanceof ($context->roleClass()))
            ->using($grant)
            ->withPivot(['forbidden', 'scope', 'expires_at']);

        $relation = $this->applyPivotTenancy($relation, $context->table('grants'));

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }
}
