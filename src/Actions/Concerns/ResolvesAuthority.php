<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions\Concerns;

use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\Model;

trait ResolvesAuthority
{
    use ConstrainsCatalogLookups;

    /**
     * A string authority names a role: grant-side calls create it on the fly.
     */
    protected function resolveAuthority(Model|string $authority, bool $createRole): Model
    {
        if ($authority instanceof Model) {
            return $authority;
        }

        $role = Context::resolve()->roleClass();

        return $createRole
            ? $this->constrainCatalogLookup($role::query())->firstOrCreate(['name' => $authority])
            : $role::query()->where('name', $authority)->firstOrFail();
    }
}
