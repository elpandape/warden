<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasPermissions
{
    /**
     * @return MorphToMany<Model, $this, Grant>
     */
    public function permissions(): MorphToMany
    {
        $context = Context::resolve();

        $permission = $context->modelClass('permission');
        /** @var class-string<Grant> $grant */
        $grant = $context->modelClass('grant');

        $relation = $this
            ->morphToMany($permission, 'entity', $context->table('grants'), relatedPivotKey: 'permission_id')
            ->using($grant)
            ->withPivot(['forbidden', 'scope']);

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }
}
