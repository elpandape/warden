<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Context;

trait ResolvesContext
{
    public function getTable(): string
    {
        return $this->table ?? Context::resolve()->table($this->contextTableKey());
    }

    public function getConnectionName(): ?string
    {
        return is_string($this->connection) ? $this->connection : Context::resolve()->connection();
    }

    abstract protected function contextTableKey(): string;
}
