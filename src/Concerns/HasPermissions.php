<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Concerns;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Tenancy\AppliesPivotTenancy;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasPermissions
{
    use AppliesPivotTenancy;

    /**
     * @return MorphToMany<\ElPandaPe\Bouncer\Models\Permission, $this, Grant>
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
