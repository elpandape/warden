<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\Model;

class RevokesPermissions
{
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidden = false;

    public function __construct(private readonly Model|string|null $authority) {}

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     */
    public function to(string|array|Model $permissions, Model|string|null $entity = null): static
    {
        return $this->revoke($permissions, $entity, onlyOwned: false);
    }

    /**
     * Revoke ownership-scoped grants only — plain grants stay untouched.
     *
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwn(Model|string $entity, string|array $permissions = '*'): static
    {
        return $this->revoke($permissions, $entity, onlyOwned: true);
    }

    /**
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwnEverything(string|array $permissions = '*'): static
    {
        return $this->toOwn('*', $permissions);
    }

    public function everything(): static
    {
        return $this->to('*', '*');
    }

    public function toManage(Model|string $entity): static
    {
        return $this->to('*', $entity);
    }

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     */
    private function revoke(string|array|Model $permissions, Model|string|null $entity, bool $onlyOwned): static
    {
        // Resolve first: revoking from a role that does not exist must fail fast.
        $authority = $this->authority === null
            ? null
            : $this->resolveAuthority($this->authority, createRole: false);

        $keys = $this->findPermissions($permissions, $entity, $onlyOwned);

        if ($keys === []) {
            return $this;
        }

        Context::resolve()->grantClass()::query()
            ->whereIn('permission_id', $keys)
            ->where('forbidden', $this->forbidden)
            ->where('entity_type', $authority?->getMorphClass())
            ->where('entity_id', $authority?->getKey())
            ->delete();

        return $this;
    }
}
