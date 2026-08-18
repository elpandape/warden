<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Contracts;

use ElPandaPe\Warden\Checks\Verdict;
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
