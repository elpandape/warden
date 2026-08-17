<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Tenancy;

use Closure;
use ElPandaPe\Bouncer\Contracts\TenantResolver;

final class Tenancy
{
    private int|string|null $tenant = null;

    private bool $resolved = false;

    private bool $onlyRelations = false;

    private bool $scopeRoleGrants = true;

    public function __construct(private readonly ?TenantResolver $resolver = null) {}

    public function to(int|string $tenant): self
    {
        $this->tenant = $tenant;
        $this->resolved = true;

        return $this;
    }

    public function remove(): self
    {
        $this->tenant = null;
        $this->resolved = true;

        return $this;
    }

    public function current(): int|string|null
    {
        if (! $this->resolved) {
            $this->tenant = $this->resolver?->resolve();
            $this->resolved = true;
        }

        return $this->tenant;
    }

    /**
     * Run the callback under a temporary tenant, restoring the previous one
     * even when the callback throws.
     */
    public function onceTo(int|string $tenant, Closure $callback): mixed
    {
        $previous = $this->current();

        $this->to($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->remove() : $this->to($previous);
        }
    }

    public function removeOnce(Closure $callback): mixed
    {
        $previous = $this->current();

        $this->remove();

        try {
            return $callback();
        } finally {
            $previous === null ? $this->remove() : $this->to($previous);
        }
    }

    /**
     * Keep the role and permission catalog global; scope only the pivots.
     */
    public function onlyRelations(bool $only = true): self
    {
        $this->onlyRelations = $only;

        return $this;
    }

    /**
     * Keep the grants given to roles global across tenants.
     */
    public function dontScopeRoleGrants(bool $dont = true): self
    {
        $this->scopeRoleGrants = ! $dont;

        return $this;
    }

    public function scopesCatalog(): bool
    {
        return ! $this->onlyRelations;
    }

    public function scopesRoleGrants(): bool
    {
        return $this->scopeRoleGrants;
    }

    /**
     * The single source of truth for READ visibility:
     * ['both', tenant] = scope NULL or tenant; ['null', null] = only global; null = unfiltered.
     *
     * @return array{0: 'both'|'null', 1: int|string|null}|null
     */
    public function readFilter(): ?array
    {
        $tenant = $this->current();

        if ($tenant !== null) {
            return ['both', $tenant];
        }

        return \ElPandaPe\Bouncer\Support\Config::scopeNullBehavior() === 'strict'
            ? ['null', null]
            : null;
    }

    /**
     * The exact scope a write (create or delete) must target — never a range.
     */
    public function writeScope(bool $forRoleGrant = false): int|string|null
    {
        if ($forRoleGrant && ! $this->scopesRoleGrants()) {
            return null;
        }

        return $this->current();
    }
}
