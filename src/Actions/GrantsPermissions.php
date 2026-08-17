<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Actions\Concerns\ResolvesAuthority;
use ElPandaPe\Bouncer\Actions\Concerns\ResolvesPermissions;
use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\Model;

class GrantsPermissions
{
    use ResolvesAuthority;
    use ResolvesPermissions;

    protected bool $forbidding = false;

    public function __construct(private readonly Model|string|null $authority) {}

    /**
     * @param  string|array<int, mixed>|Model  $permissions
     */
    public function to(string|array|Model $permissions, Model|string|null $entity = null): static
    {
        $this->grant($this->findOrCreatePermissions($permissions, $entity));

        return $this;
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
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwn(Model|string $entity, string|array $permissions = '*'): static
    {
        $this->grant($this->findOrCreatePermissions($permissions, $entity, onlyOwned: true));

        return $this;
    }

    /**
     * @param  string|array<int, mixed>  $permissions
     */
    public function toOwnEverything(string|array $permissions = '*'): static
    {
        return $this->toOwn('*', $permissions);
    }

    /**
     * @param  list<int|string>  $permissionKeys
     */
    protected function grant(array $permissionKeys): void
    {
        $grantClass = Context::resolve()->grantClass();
        $authority = $this->authority === null
            ? null
            : $this->resolveAuthority($this->authority, createRole: true);

        foreach ($permissionKeys as $key) {
            // firstOrCreate self-heals concurrent races via createOrFirst on Laravel 12+.
            $grantClass::query()->firstOrCreate([
                'permission_id' => $key,
                'entity_type' => $authority?->getMorphClass(),
                'entity_id' => $authority?->getKey(),
                'forbidden' => $this->forbidding,
            ]);
        }
    }
}
