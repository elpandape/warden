<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions\Concerns;

use BackedEnum;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Support\Name;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait NormalizesRoles
{
    use ConstrainsCatalogLookups;
    use ValidatesModels;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     * @return list<string|Model>
     */
    private function normalizeRoles(string|array|Model|BackedEnum $roles): array
    {
        $items = is_array($roles) ? array_values($roles) : [$roles];
        $normalized = [];

        foreach ($items as $item) {
            if ($item instanceof BackedEnum) {
                $item = Name::of($item);
            }

            if (! is_string($item) && ! $item instanceof Model) {
                throw new InvalidArgumentException('Roles must be names or role models.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Resolve names and models to role models, creating the named ones on the fly.
     *
     * @param  list<string|Model>  $roles
     * @return list<Model>
     */
    private function resolveRoleModels(array $roles): array
    {
        $roleClass = Context::resolve()->roleClass();
        $models = [];

        foreach ($roles as $role) {
            $models[] = $role instanceof Model
                ? $this->assertModelOf($role, $roleClass, 'role')
                : $this->constrainCatalogLookup($roleClass::query())->firstOrCreate(['name' => $role]);
        }

        return $models;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     * @return list<Model>
     */
    private function normalizeAuthorities(Model|array $authorities): array
    {
        $items = is_array($authorities) ? array_values($authorities) : [$authorities];
        $normalized = [];

        foreach ($items as $item) {
            if (! $item instanceof Model) {
                throw new InvalidArgumentException('Authorities must be model instances.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
