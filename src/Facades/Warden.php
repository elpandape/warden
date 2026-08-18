<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Facades;

use ElPandaPe\Warden\Actions\AssignsRoles;
use ElPandaPe\Warden\Actions\ChecksRoles;
use ElPandaPe\Warden\Actions\ForbidsPermissions;
use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Actions\RetractsRoles;
use ElPandaPe\Warden\Actions\RevokesPermissions;
use ElPandaPe\Warden\Actions\SyncsRolesAndPermissions;
use ElPandaPe\Warden\Actions\UnforbidsPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static GrantsPermissions allow(Model|string|\BackedEnum $authority)
 * @method static GrantsPermissions allowEveryone()
 * @method static ForbidsPermissions forbid(Model|string|\BackedEnum $authority)
 * @method static ForbidsPermissions forbidEveryone()
 * @method static RevokesPermissions disallow(Model|string|\BackedEnum $authority)
 * @method static RevokesPermissions disallowEveryone()
 * @method static UnforbidsPermissions unforbid(Model|string|\BackedEnum $authority)
 * @method static UnforbidsPermissions unforbidEveryone()
 * @method static AssignsRoles assign(string|array<int, mixed>|Model $roles)
 * @method static RetractsRoles retract(string|array<int, mixed>|Model $roles)
 * @method static SyncsRolesAndPermissions sync(Model|string|\BackedEnum $authority)
 * @method static ChecksRoles is(Model $authority)
 * @method static bool can(string|\BackedEnum $permission, Model|string|null $entity = null)
 * @method static bool cannot(string|\BackedEnum $permission, Model|string|null $entity = null)
 * @method static bool canAny(array<int, string|\BackedEnum> $permissions, Model|string|null $entity = null)
 * @method static \Illuminate\Auth\Access\Response authorize(string|\BackedEnum $permission, Model|string|null $entity = null)
 * @method static \ElPandaPe\Warden\Warden ownedVia(string|\Closure $modelOrAttribute, string|\Closure|null $attribute = null)
 * @method static \Illuminate\Database\Eloquent\Model findRole(string|\BackedEnum $name)
 * @method static \Illuminate\Database\Eloquent\Model findPermission(string|\BackedEnum $name)
 * @method static \ElPandaPe\Warden\Testing\WardenFake fake()
 * @method static \ElPandaPe\Warden\Checks\Explain\AuthorizationExplanation explain(\Illuminate\Database\Eloquent\Model $authority, string|\BackedEnum $permission, \Illuminate\Database\Eloquent\Model|string|null $entity = null)
 * @method static \ElPandaPe\Warden\Warden restrictedVia(string|\Closure $contextOrAttribute, string|\Closure|null $attribute = null)
 * @method static \ElPandaPe\Warden\Warden refresh()
 * @method static \ElPandaPe\Warden\Warden refreshFor(\Illuminate\Database\Eloquent\Model $authority)
 * @method static \ElPandaPe\Warden\Tenancy\Tenancy tenant()
 * @method static \ElPandaPe\Warden\Tenancy\Tenancy scope()
 * @method static Model role(array<string, mixed> $attributes = [])
 * @method static Model permission(array<string, mixed> $attributes = [])
 *
 * @see \ElPandaPe\Warden\Warden
 */
final class Warden extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ElPandaPe\Warden\Warden::class;
    }
}
