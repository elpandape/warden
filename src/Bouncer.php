<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

use ElPandaPe\Bouncer\Actions\AssignsRoles;
use ElPandaPe\Bouncer\Actions\ChecksRoles;
use ElPandaPe\Bouncer\Actions\ForbidsPermissions;
use ElPandaPe\Bouncer\Actions\GrantsPermissions;
use ElPandaPe\Bouncer\Actions\RetractsRoles;
use ElPandaPe\Bouncer\Actions\RevokesPermissions;
use ElPandaPe\Bouncer\Actions\SyncsRolesAndPermissions;
use ElPandaPe\Bouncer\Actions\UnforbidsPermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

final class Bouncer
{
    public function allow(Model|string $authority): GrantsPermissions
    {
        return new GrantsPermissions($authority);
    }

    public function allowEveryone(): GrantsPermissions
    {
        return new GrantsPermissions(null);
    }

    public function forbid(Model|string $authority): ForbidsPermissions
    {
        return new ForbidsPermissions($authority);
    }

    public function forbidEveryone(): ForbidsPermissions
    {
        return new ForbidsPermissions(null);
    }

    public function disallow(Model|string $authority): RevokesPermissions
    {
        return new RevokesPermissions($authority);
    }

    public function disallowEveryone(): RevokesPermissions
    {
        return new RevokesPermissions(null);
    }

    public function unforbid(Model|string $authority): UnforbidsPermissions
    {
        return new UnforbidsPermissions($authority);
    }

    public function unforbidEveryone(): UnforbidsPermissions
    {
        return new UnforbidsPermissions(null);
    }

    /**
     * @param  string|array<int, mixed>|Model  $roles
     */
    public function assign(string|array|Model $roles): AssignsRoles
    {
        return new AssignsRoles($roles);
    }

    /**
     * @param  string|array<int, mixed>|Model  $roles
     */
    public function retract(string|array|Model $roles): RetractsRoles
    {
        return new RetractsRoles($roles);
    }

    public function sync(Model|string $authority): SyncsRolesAndPermissions
    {
        return new SyncsRolesAndPermissions($authority);
    }

    public function is(Model $authority): ChecksRoles
    {
        return new ChecksRoles($authority);
    }

    public function can(string $permission, Model|string|null $entity = null): bool
    {
        return $this->gate()->allows($permission, $this->arguments($entity));
    }

    public function cannot(string $permission, Model|string|null $entity = null): bool
    {
        return ! $this->can($permission, $entity);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function canAny(array $permissions, Model|string|null $entity = null): bool
    {
        return $this->gate()->any($permissions, $this->arguments($entity));
    }

    public function authorize(string $permission, Model|string|null $entity = null): Response
    {
        return $this->gate()->authorize($permission, $this->arguments($entity));
    }

    public function ownedVia(string|\Closure $modelOrAttribute, string|\Closure|null $attribute = null): static
    {
        Context::resolve()->ownedVia($modelOrAttribute, $attribute);

        return $this;
    }

    public function tenant(): Database\Tenancy\Tenancy
    {
        return app(Database\Tenancy\Tenancy::class);
    }

    /**
     * Alias kept for familiarity with the original package.
     */
    public function scope(): Database\Tenancy\Tenancy
    {
        return $this->tenant();
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
