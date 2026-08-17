<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models\Concerns;

use ElPandaPe\Bouncer\Context;

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
