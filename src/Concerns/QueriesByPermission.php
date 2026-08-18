<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Concerns;

use BackedEnum;
use ElPandaPe\Bouncer\Checks\Queries\WhereCan;
use ElPandaPe\Bouncer\Support\Name;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For the models being authorized (posts, documents…), not the authorities:
 * Post::whereCan($user, 'view')->paginate() answers "over which rows".
 */
trait QueriesByPermission
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereCan(Builder $query, Model $authority, string|BackedEnum $permission): Builder
    {
        /** @var Builder<static> */
        return app(WhereCan::class)->apply($query, $authority, Name::of($permission));
    }
}
