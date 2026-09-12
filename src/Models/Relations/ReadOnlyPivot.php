<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Relations;

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The pivot a loaded IsRole::nestedRoles() edge carries. It finds its row by the
 * edge's two keys alone, so a write through it would reach every model's
 * assignment of that role, in every tenant, unannounced: every write refuses,
 * and only a save with nothing to write goes through, so push() keeps working.
 */
final class ReadOnlyPivot extends Pivot
{
    public function save(array $options = []): bool
    {
        if ($this->exists && ! $this->isDirty()) {
            return parent::save($options);
        }

        throw $this->refused(__FUNCTION__);
    }

    public function saveOrIgnore(mixed $options = [], mixed $uniqueBy = null): never
    {
        throw $this->refused(__FUNCTION__);
    }

    public function delete(): never
    {
        throw $this->refused(__FUNCTION__);
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw $this->refused($method);
    }

    protected function incrementOrDecrementEach(mixed $columns, mixed $extra, string $method): never
    {
        throw $this->refused($method);
    }

    private function refused(string $writer): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            '%s() on a nestedRoles() pivot is refused: nest a role with Warden::assign($inner)->to($outer) and unnest it with Warden::retract($inner)->from($outer).',
            $writer,
        ));
    }
}
