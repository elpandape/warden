<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions\Concerns;

use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait ResolvesPermissions
{
    use ValidatesModels;

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     * @return list<int|string>
     */
    protected function findOrCreatePermissions(
        string|array|Model $permissions,
        Model|string|null $entity,
        bool $onlyOwned = false,
    ): array {
        $keys = [];
        $model = Context::resolve()->permissionClass();

        foreach ($this->normalizePermissions($permissions) as $permission) {
            if ($permission instanceof Model) {
                $keys[] = $this->modelKey($this->assertModelOf($permission, $model, 'permission'));

                continue;
            }

            $attributes = [
                'name' => $permission,
                ...$this->entityAttributes($entity),
                'only_owned' => $onlyOwned,
            ];

            $keys[] = $this->modelKey($model::query()->firstOrCreate($attributes));
        }

        return $keys;
    }

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     * @return list<int|string>
     */
    protected function findPermissions(
        string|array|Model $permissions,
        Model|string|null $entity,
        bool $onlyOwned = false,
    ): array {
        $keys = [];
        $names = [];
        $model = Context::resolve()->permissionClass();

        foreach ($this->normalizePermissions($permissions) as $permission) {
            if ($permission instanceof Model) {
                $keys[] = $this->modelKey($this->assertModelOf($permission, $model, 'permission'));

                continue;
            }

            $names[] = $permission;
        }

        if ($names === []) {
            return $keys;
        }

        $query = $model::query()
            ->whereIn('name', $names)
            ->where('only_owned', $onlyOwned);

        foreach ($this->entityAttributes($entity) as $column => $value) {
            $query->where($column, $value);
        }

        // Resolve through Eloquent so global scopes on custom models keep applying.
        foreach ($query->get() as $found) {
            $keys[] = $this->modelKey($found);
        }

        return $keys;
    }

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     * @return list<string|Model>
     */
    private function normalizePermissions(string|array|Model $permissions): array
    {
        $items = is_array($permissions) ? array_values($permissions) : [$permissions];
        $normalized = [];

        foreach ($items as $item) {
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
