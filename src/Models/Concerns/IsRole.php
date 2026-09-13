<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Concerns\HasPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleUpdated;
use ElPandaPe\Warden\Models\Relations\ReadOnlyBelongsToMany;
use ElPandaPe\Warden\Models\Relations\ReadOnlyPivot;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use ElPandaPe\Warden\Support\Titles\RoleTitle;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use WeakMap;

/**
 * @phpstan-import-type RoleShape from RoleSnapshot
 */
trait IsRole
{
    use BelongsToTenant;
    use HasPermissions;
    use ResolvesContext;

    /**
     * The roles nested inside this one: an edge between roles, read through a
     * relation of its own rather than by giving the role model the authority
     * concern — that would drag roles(), isA() and the tenancy scopes onto it
     * and make every role an authority for the whole engine.
     *
     * The edge is stored in assigned_roles like any other assignment, with
     * this role as the authority, so the schema is untouched. The relation only
     * reads it: assign() and retract() write it.
     *
     * @return BelongsToMany<\ElPandaPe\Warden\Models\Role, $this>
     */
    public function nestedRoles(): BelongsToMany
    {
        $context = Context::resolve();
        $inner = $this->newRelatedInstance($context->roleClass());

        $relation = new ReadOnlyBelongsToMany(
            $inner->newQuery(),
            $this,
            $context->table('assigned_roles'),
            'entity_id',
            'role_id',
            $this->getKeyName(),
            $inner->getKeyName(),
            'nestedRoles',
        );

        return $relation->using(ReadOnlyPivot::class)->wherePivot('entity_type', $this->getMorphClass());
    }

    /**
     * @return RoleShape
     */
    public function snapshot(): array
    {
        return RoleSnapshot::of($this);
    }

    protected static function bootIsRole(): void
    {
        static::creating(function (Model $role): void {
            if (Config::titlesAutogenerate() && $role->getAttribute('title') === null) {
                $name = $role->getAttribute('name');

                $role->setAttribute('title', RoleTitle::generate(is_string($name) ? $name : ''));
            }
        });

        // Lifecycle events fire at the model layer: every creation path counts.
        static::created(function (Model $role): void {
            Announcer::announce(new RoleCreated($role, actor: app(ActorResolver::class)->resolve()));
        });

        /** @var WeakMap<Model, array<mixed>> $stored */
        $stored = new WeakMap;

        static::updating(function (Model $role) use ($stored): void {
            if (! Config::eventsEnabled()) {
                return;
            }

            $row = $role->getRawOriginal();
            $missing = array_values(array_diff(['name', 'title', 'scope'], array_keys($row)));

            // A column the model never read would photograph as null: take it
            // from the stored row, which the update has not reached yet.
            if ($missing !== []) {
                $row = [...$row, ...(array) $role->newQueryWithoutScopes()->whereKey($role->getKey())->toBase()->first($missing)];
            }

            $stored[$role] = $row;
        });

        static::updated(function (Model $role) use ($stored): void {
            $row = $stored[$role] ?? null;
            unset($stored[$role]);

            if ($row === null) {
                return;
            }

            $before = RoleSnapshot::of($role->newInstance([], true)->setRawAttributes($row, true));
            $after = RoleSnapshot::of($role->newInstance([], true)->setRawAttributes([...$row, ...$role->getAttributes()], true));
            $changed = array_values(array_filter(
                array_keys($after),
                static fn (string $key): bool => $key !== 'v' && $key !== 'key' && $before[$key] !== $after[$key],
            ));

            if ($changed !== []) {
                Announcer::announce(new RoleUpdated($role, $before, $after, $changed, actor: app(ActorResolver::class)->resolve()));
            }
        });

        static::deleted(function (Model $role): void {
            $invalidations = app(CacheInvalidations::class);

            $invalidations->settleCascade($role);
            $held = $invalidations->pullHeld($role);

            Announcer::announce(new RoleDeleted(
                $role,
                actor: app(ActorResolver::class)->resolve(),
                heldGrants: $held['grants'],
                heldRoles: $held['roles'],
            ));
            $invalidations->announceCascade($role);
        });
    }

    protected function contextTableKey(): string
    {
        return 'roles';
    }
}
