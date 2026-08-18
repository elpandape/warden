<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Contracts;

use Illuminate\Database\Eloquent\Model;

interface Constraint
{
    /**
     * Whether the checked entity satisfies this constraint.
     */
    public function passes(Model $entity, ?Model $authority): bool;

    /**
     * The serializable shape, discriminated by type — never by class name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
