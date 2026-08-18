<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Exceptions\RoleDoesNotExist;
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

        if ($createRole) {
            return $this->constrainCatalogLookup($role::query())->firstOrCreate(['name' => $authority]);
        }

        return $role::query()->where('name', $authority)->first()
            ?? throw RoleDoesNotExist::named($authority);
    }
}
