<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Relations;

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The relation behind IsRole::nestedRoles(). A nesting edge is an assignment,
 * and only the fluent API writes one with its tenant, cache bump and events, so
 * every write this relation declares refuses before it touches a row.
 *
 * touch() and touchIfTouching() stay open: they write no edge, and
 * Model::touchOwners() calls touch() for any relation a model lists in $touches.
 * Writes that reach the roles table instead of the edge, such as delete() or
 * rawUpdate(), work as they do through any relation.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
final class ReadOnlyBelongsToMany extends BelongsToMany
{
    public function attach(mixed $ids, mixed $attributes = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function attachOrFail(mixed $ids, mixed $attributes = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function detach(mixed $ids = null, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function detachOrFail(mixed $ids = null, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function sync(mixed $ids, mixed $detaching = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function syncOrFail(mixed $ids, mixed $detaching = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function syncWithoutDetaching(mixed $ids): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function syncWithoutDetachingOrFail(mixed $ids): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function syncWithPivotValues(mixed $ids, mixed $values, mixed $detaching = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function syncWithPivotValuesOrFail(mixed $ids, mixed $values, mixed $detaching = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function toggle(mixed $ids, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function toggleOrFail(mixed $ids, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function updateExistingPivot(mixed $id, mixed $attributes, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function updateExistingPivotOrFail(mixed $id, mixed $attributes, mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function save(mixed $model, mixed $pivotAttributes = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function saveQuietly(mixed $model, mixed $pivotAttributes = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function saveMany(mixed $models, mixed $pivotAttributes = []): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function saveManyQuietly(mixed $models, mixed $pivotAttributes = []): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function create(mixed $attributes = [], mixed $joining = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function createMany(mixed $records, mixed $joinings = []): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function firstOrCreate(mixed $attributes = [], mixed $values = [], mixed $joining = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function createOrFirst(mixed $attributes = [], mixed $values = [], mixed $joining = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function updateOrCreate(mixed $attributes, mixed $values = [], mixed $joining = [], mixed $touch = true): never
    {
        throw $this->refused(__FUNCTION__);
    }

    private function refused(string $writer): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            '%s()->%s() is read-only: nest a role with Warden::assign($inner)->to($outer) and unnest it with Warden::retract($inner)->from($outer).',
            $this->getRelationName(),
            $writer,
        ));
    }
}
