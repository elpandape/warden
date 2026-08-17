<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Contracts;

use ElPandaPe\Bouncer\Verdict;
use Illuminate\Database\Eloquent\Model;

interface Resolver
{
    /**
     * Resolve a permission check to an explicit verdict:
     * granted (with the deciding permission key), forbidden, or abstained.
     */
    public function resolve(
        Model $authority,
        string $permission,
        Model|string|null $entity = null,
    ): Verdict;
}
