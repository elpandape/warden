<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Testing;

use BackedEnum;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;

/**
 * Arrange-phase sugar for app test suites.
 */
trait WithPermissions
{
    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    protected function allowUser(Model $authority, string|array|BackedEnum $permissions, Model|string|null $entity = null): void
    {
        app(Warden::class)->allow($authority)->to($permissions, $entity);
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $permissions
     */
    protected function forbidUser(Model $authority, string|array|BackedEnum $permissions, Model|string|null $entity = null): void
    {
        app(Warden::class)->forbid($authority)->to($permissions, $entity);
    }

    /**
     * @param  string|array<int, mixed>|BackedEnum  $roles
     */
    protected function assignRoles(Model $authority, string|array|BackedEnum $roles): void
    {
        app(Warden::class)->assign($roles)->to($authority);
    }
}
