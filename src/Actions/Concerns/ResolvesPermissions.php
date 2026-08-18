<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions\Concerns;

use BackedEnum;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Support\Name;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait ResolvesPermissions
{
    use ConstrainsCatalogLookups;
    use ValidatesModels;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     * @return list<Model>
     */
    protected function findOrCreatePermissions(
        string|array|Model|BackedEnum $permissions,
        Model|string|null $entity,
        bool $onlyOwned = false,
    ): array {
        $found = [];
        $model = Context::resolve()->permissionClass();

        foreach ($this->normalizePermissions($permissions) as $permission) {
            if ($permission instanceof Model) {
                $found[] = $this->assertModelOf($permission, $model, 'permission');

                continue;
            }

            // Plain by construction: a constrained twin must never absorb
            // an unconstrained grant, nor lend it its conditions.
            $attributes = [
                'name' => $permission,
                ...$this->entityAttributes($entity),
                'only_owned' => $onlyOwned,
                'options' => null,
            ];

            $found[] = $this->constrainCatalogLookup($model::query())->firstOrCreate($attributes);
        }

        return $found;
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     * @return list<Model>
     */
    protected function findPermissions(
        string|array|Model|BackedEnum $permissions,
        Model|string|null $entity,
        bool $onlyOwned = false,
    ): array {
        $found = [];
        $names = [];
        $model = Context::resolve()->permissionClass();

        foreach ($this->normalizePermissions($permissions) as $permission) {
            if ($permission instanceof Model) {
                $found[] = $this->assertModelOf($permission, $model, 'permission');

                continue;
            }

            $names[] = $permission;
        }

        if ($names === []) {
            return $found;
        }

        $query = $model::query()
            ->whereIn('name', $names)
            ->where('only_owned', $onlyOwned);

        foreach ($this->entityAttributes($entity) as $column => $value) {
            $query->where($column, $value);
        }

        // Resolve through Eloquent so global scopes on custom models keep applying.
        foreach ($query->get() as $permission) {
            $found[] = $permission;
        }

        return $found;
    }

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $permissions
     * @return list<string|Model>
     */
    private function normalizePermissions(string|array|Model|BackedEnum $permissions): array
    {
        $items = is_array($permissions) ? array_values($permissions) : [$permissions];
        $normalized = [];

        foreach ($items as $item) {
            if ($item instanceof BackedEnum) {
                $item = Name::of($item);
            }

            if (! is_string($item) && ! $item instanceof Model) {
                throw new InvalidArgumentException('Permissions must be names or permission models.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @return array{entity_type: string|null, entity_id: int|string|null}
     */
    private function entityAttributes(Model|string|null $entity): array
    {
        if ($entity === null) {
            return ['entity_type' => null, 'entity_id' => null];
        }

        if ($entity === '*') {
            return ['entity_type' => '*', 'entity_id' => null];
        }

        if (is_string($entity)) {
            if (! is_subclass_of($entity, Model::class)) {
                throw new InvalidArgumentException("Entity [{$entity}] must be a model class, an instance, or '*'.");
            }

            return ['entity_type' => (new $entity)->getMorphClass(), 'entity_id' => null];
        }

        if (! $entity->exists) {
            throw new InvalidArgumentException(
                'The entity model does not exist. To cover all instances, pass the class name instead.',
            );
        }

        // Failing here beats failing open: a null id would widen the grant to the whole class.
        return [
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $this->modelKey($entity),
        ];
    }
}
