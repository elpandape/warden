<?php

declare(strict_types=1);

namespace ElPandaPe\Warden;

use ElPandaPe\Warden\Actions\AssignsRoles;
use ElPandaPe\Warden\Actions\ChecksRoles;
use ElPandaPe\Warden\Actions\ForbidsPermissions;
use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Actions\RetractsRoles;
use ElPandaPe\Warden\Actions\RevokesPermissions;
use ElPandaPe\Warden\Actions\SyncsRolesAndPermissions;
use ElPandaPe\Warden\Actions\UnforbidsPermissions;
use ElPandaPe\Warden\Exceptions\PermissionDoesNotExist;
use ElPandaPe\Warden\Exceptions\RoleDoesNotExist;
use ElPandaPe\Warden\Exceptions\UnauthorizedException;
use ElPandaPe\Warden\Support\Name;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

final class Warden
{
    public function allow(Model|string|\BackedEnum $authority): GrantsPermissions
    {
        return new GrantsPermissions($authority instanceof \BackedEnum ? Name::of($authority) : $authority);
    }

    public function allowEveryone(): GrantsPermissions
    {
        return new GrantsPermissions(null);
    }

    public function forbid(Model|string|\BackedEnum $authority): ForbidsPermissions
    {
        return new ForbidsPermissions($authority instanceof \BackedEnum ? Name::of($authority) : $authority);
    }

    public function forbidEveryone(): ForbidsPermissions
    {
        return new ForbidsPermissions(null);
    }

    public function disallow(Model|string|\BackedEnum $authority): RevokesPermissions
    {
        return new RevokesPermissions($authority instanceof \BackedEnum ? Name::of($authority) : $authority);
    }

    public function disallowEveryone(): RevokesPermissions
    {
        return new RevokesPermissions(null);
    }

    public function unforbid(Model|string|\BackedEnum $authority): UnforbidsPermissions
    {
        return new UnforbidsPermissions($authority instanceof \BackedEnum ? Name::of($authority) : $authority);
    }

    public function unforbidEveryone(): UnforbidsPermissions
    {
        return new UnforbidsPermissions(null);
    }

    /**
     * @param  string|array<int, mixed>|Model|\BackedEnum  $roles
     */
    public function assign(string|array|Model|\BackedEnum $roles): AssignsRoles
    {
        return new AssignsRoles($roles);
    }

    /**
     * @param  string|array<int, mixed>|Model|\BackedEnum  $roles
     */
    public function retract(string|array|Model|\BackedEnum $roles): RetractsRoles
    {
        return new RetractsRoles($roles);
    }

    public function sync(Model|string|\BackedEnum $authority): SyncsRolesAndPermissions
    {
        return new SyncsRolesAndPermissions($authority instanceof \BackedEnum ? Name::of($authority) : $authority);
    }

    public function is(Model $authority): ChecksRoles
    {
        return new ChecksRoles($authority);
    }

    public function can(string|\BackedEnum $permission, Model|string|null $entity = null): bool
    {
        return $this->gate()->allows(Name::of($permission), $this->arguments($entity));
    }

    public function cannot(string|\BackedEnum $permission, Model|string|null $entity = null): bool
    {
        return ! $this->can($permission, $entity);
    }

    /**
     * @param  array<int, string|\BackedEnum>  $permissions
     */
    public function canAny(array $permissions, Model|string|null $entity = null): bool
    {
        return $this->gate()->any(array_map(Name::of(...), $permissions), $this->arguments($entity));
    }

    /**
     * @throws UnauthorizedException
     */
    public function authorize(string|\BackedEnum $permission, Model|string|null $entity = null): Response
    {
        $name = Name::of($permission);

        try {
            return $this->gate()->authorize($name, $this->arguments($entity));
        } catch (AuthorizationException $exception) {
            throw UnauthorizedException::forPermissions([$name], $exception);
        }
    }

    public function ownedVia(string|\Closure $modelOrAttribute, string|\Closure|null $attribute = null): static
    {
        Context::resolve()->ownedVia($modelOrAttribute, $attribute);

        return $this;
    }

    /**
     * Configure how entities are matched against restricted-role contexts.
     */
    public function restrictedVia(string|\Closure $contextOrAttribute, string|\Closure|null $attribute = null): static
    {
        Context::resolve()->restrictedVia($contextOrAttribute, $attribute);

        return $this;
    }

    /**
     * Swap the resolver for a scriptable fake that records every check.
     */
    public function fake(): Testing\WardenFake
    {
        $fake = new Testing\WardenFake;

        app()->instance(Contracts\Resolver::class, $fake);

        return $fake;
    }

    /**
     * Why a check resolves the way it does: verdict, cause, decisive rows.
     * Always answered by the database engine, never from cache.
     */
    public function explain(Model $authority, string|\BackedEnum $permission, Model|string|null $entity = null): Checks\Explain\AuthorizationExplanation
    {
        return new Checks\Explain\Explainer(Context::resolve())->explain($authority, $permission, $entity);
    }

    /**
     * Invalidate every cached check at once (O(1): a version bump).
     */
    public function refresh(): static
    {
        app(Checks\Resolvers\CacheKeyVersioner::class)->refreshAll();

        return $this;
    }

    /**
     * Drop the cached payload for one authority under the current tenant.
     */
    public function refreshFor(Model $authority): static
    {
        $resolver = app(Contracts\Resolver::class);

        if ($resolver instanceof Checks\Resolvers\CachedResolver) {
            $resolver->forgetFor($authority);
        }

        return $this;
    }

    public function tenant(): Tenancy\Tenancy
    {
        return app(Tenancy\Tenancy::class);
    }

    /**
     * Alias kept for familiarity with the original package.
     */
    public function scope(): Tenancy\Tenancy
    {
        return $this->tenant();
    }

    /**
     * Find a role by name under the current tenant, or fail loudly.
     */
    public function findRole(string|\BackedEnum $name): Model
    {
        $role = Context::resolve()->roleClass();

        return $role::query()->where('name', Name::of($name))->first()
            ?? throw RoleDoesNotExist::named(Name::of($name));
    }

    /**
     * Find a permission by name under the current tenant, or fail loudly.
     * With entity-scoped shapes sharing a name, the first match is returned.
     */
    public function findPermission(string|\BackedEnum $name): Model
    {
        $permission = Context::resolve()->permissionClass();

        return $permission::query()->where('name', Name::of($name))->first()
            ?? throw PermissionDoesNotExist::named(Name::of($name));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function role(array $attributes = []): Model
    {
        $role = Context::resolve()->roleClass();

        return new $role($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function permission(array $attributes = []): Model
    {
        $permission = Context::resolve()->permissionClass();

        return new $permission($attributes);
    }

    /**
     * @return list<Model|string>
     */
    private function arguments(Model|string|null $entity): array
    {
        return $entity === null ? [] : [$entity];
    }

    private function gate(): Gate
    {
        return app(Gate::class);
    }
}
