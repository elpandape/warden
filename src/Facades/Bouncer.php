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
 * @method static GrantsPermissions allow(Model|string $authority)
 * @method static GrantsPermissions allowEveryone()
 * @method static ForbidsPermissions forbid(Model|string $authority)
 * @method static ForbidsPermissions forbidEveryone()
 * @method static RevokesPermissions disallow(Model|string $authority)
 * @method static RevokesPermissions disallowEveryone()
 * @method static UnforbidsPermissions unforbid(Model|string $authority)
 * @method static UnforbidsPermissions unforbidEveryone()
 * @method static AssignsRoles assign(string|array<int, mixed>|Model $roles)
 * @method static RetractsRoles retract(string|array<int, mixed>|Model $roles)
 * @method static SyncsRolesAndPermissions sync(Model|string $authority)
 * @method static ChecksRoles is(Model $authority)
 * @method static bool can(string $permission, Model|string|null $entity = null)
 * @method static bool cannot(string $permission, Model|string|null $entity = null)
 * @method static bool canAny(array<int, string> $permissions, Model|string|null $entity = null)
 * @method static \Illuminate\Auth\Access\Response authorize(string $permission, Model|string|null $entity = null)
 * @method static \ElPandaPe\Bouncer\Bouncer ownedVia(string|\Closure $modelOrAttribute, string|\Closure|null $attribute = null)
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
