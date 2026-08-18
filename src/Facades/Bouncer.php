<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Facades;

use ElPandaPe\Bouncer\Actions\AssignsRoles;
use ElPandaPe\Bouncer\Actions\ChecksRoles;
use ElPandaPe\Bouncer\Actions\ForbidsPermissions;
use ElPandaPe\Bouncer\Actions\GrantsPermissions;
use ElPandaPe\Bouncer\Actions\RetractsRoles;
use ElPandaPe\Bouncer\Actions\RevokesPermissions;
use ElPandaPe\Bouncer\Actions\SyncsRolesAndPermissions;
use ElPandaPe\Bouncer\Actions\UnforbidsPermissions;
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
 * @method static \ElPandaPe\Bouncer\Bouncer ownedVia(string|\Closure $modelOrAttribute, string|\Closure|null $attribute = null)
 * @method static \Illuminate\Database\Eloquent\Model findRole(string|\BackedEnum $name)
 * @method static \Illuminate\Database\Eloquent\Model findPermission(string|\BackedEnum $name)
 * @method static \ElPandaPe\Bouncer\Testing\BouncerFake fake()
 * @method static \ElPandaPe\Bouncer\Checks\Explain\AuthorizationExplanation explain(\Illuminate\Database\Eloquent\Model $authority, string|\BackedEnum $permission, \Illuminate\Database\Eloquent\Model|string|null $entity = null)
 * @method static \ElPandaPe\Bouncer\Bouncer restrictedVia(string|\Closure $contextOrAttribute, string|\Closure|null $attribute = null)
 * @method static \ElPandaPe\Bouncer\Bouncer refresh()
 * @method static \ElPandaPe\Bouncer\Bouncer refreshFor(\Illuminate\Database\Eloquent\Model $authority)
 * @method static \ElPandaPe\Bouncer\Tenancy\Tenancy tenant()
 * @method static \ElPandaPe\Bouncer\Tenancy\Tenancy scope()
 * @method static Model role(array<string, mixed> $attributes = [])
 * @method static Model permission(array<string, mixed> $attributes = [])
 *
 * @see \ElPandaPe\Bouncer\Bouncer
 */
final class Bouncer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ElPandaPe\Bouncer\Bouncer::class;
    }
}
