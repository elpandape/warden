<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionRestored;
use ElPandaPe\Warden\Events\PermissionUpdated;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Operations;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use ElPandaPe\Warden\Tenancy\AppliesPivotTenancy;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use WeakMap;

/**
 * @phpstan-import-type PermissionShape from PermissionSnapshot
 */
trait IsPermission
{
    use AppliesPivotTenancy;
    use BelongsToTenant;
    use ResolvesContext;

    /**
     * @return MorphToMany<\ElPandaPe\Warden\Models\Role, $this, Grant>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->roleClass();
        $grant = $context->grantClass();

        $relation = $this
            ->scopedMorphToMany($role, $context->table('grants'), 'permission_id', 'entity_id', 'roles', inverse: true, roleGrant: true)
            ->using($grant)
            ->withPivot(['forbidden', 'scope', 'expires_at']);

        $relation = $this->applyPivotTenancy($relation, $context->table('grants'));

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }

    /**
     * @return PermissionShape
     */
    public function snapshot(): array
    {
        return PermissionSnapshot::of($this);
    }

    protected static function bootIsPermission(): void
    {
        static::creating(function (Model $permission): void {
            if (Config::titlesAutogenerate() && $permission->getAttribute('title') === null) {
                $name = $permission->getAttribute('name');
                $type = $permission->getAttribute('entity_type');
                $entityId = $permission->getAttribute('entity_id');

                $permission->setAttribute('title', PermissionTitle::generate(
                    name: is_string($name) ? $name : '',
                    entityType: is_string($type) ? $type : null,
                    entityId: is_int($entityId) || is_string($entityId) ? $entityId : null,
                    onlyOwned: (bool) $permission->getAttribute('only_owned'),
                ));
            }
        });

        static::saving(function (Model $permission): void {
            if (! $permission->exists) {
                // The scope is part of the identity, so it has to be settled before
                // the key is computed rather than stamped afterwards. Only a new row
                // takes the active tenant: an update that never read the scope is
                // not a move.
                BelongsToTenant::stampScope($permission, static::tenantCatalog());
            } elseif (! $permission->wasRecentlyCreated && array_diff(PermissionIdentity::COLUMNS, array_keys($permission->getAttributes())) !== []) {
                // A column that was not read would print as null, so the row keeps
                // the key it has and may not change what that key identifies. A row
                // created in this request is whole: what it left unset holds the
                // column default.
                if ($permission->isDirty(PermissionIdentity::COLUMNS)) {
                    throw new ConfigurationException('Load the whole permission row before changing what identifies it.');
                }

                return;
            }

            $permission->setAttribute('identity_key', PermissionIdentity::for($permission));
        });

        // Lifecycle events fire at the model layer: every creation path counts.
        static::created(function (Model $permission): void {
            app(Operations::class)->during(function () use ($permission): void {
                Announcer::announce(fn (): PermissionCreated => new PermissionCreated($permission, actor: app(ActorResolver::class)->resolve(), operation: app(Operations::class)->current()));
            });
        });

        /** @var WeakMap<Model, array<mixed>> $stored */
        $stored = new WeakMap;

        static::updating(function (Model $permission) use ($stored): void {
            if (! Config::eventsEnabled()) {
                return;
            }

            $row = $permission->getRawOriginal();
            $missing = array_values(array_diff(['name', 'title', 'entity_type', 'entity_id', 'only_owned', 'scope', 'options'], array_keys($row)));

            // A column the model never read would photograph as a default: take
            // it from the stored row, which the update has not reached yet.
            if ($missing !== []) {
                $row = [...$row, ...(array) $permission->newQueryWithoutScopes()->whereKey($permission->getKey())->toBase()->first($missing)];
            }

            $stored[$permission] = $row;
        });

        static::updated(function (Model $permission) use ($stored): void {
            // First, events on or off, so no listener reads what this edit
            // made stale. One boundary: a scope both marks reach bumps once.
            $invalidations = app(CacheInvalidations::class);
            $invalidations->during(static function () use ($invalidations, $permission): void {
                $invalidations->markFrom($permission);
                $invalidations->markCatalogEdit($permission);
            });

            app(Operations::class)->during(function () use ($permission, $stored): void {
                $row = $stored[$permission] ?? null;
                unset($stored[$permission]);

                if ($row === null) {
                    return;
                }

                $before = PermissionSnapshot::of($permission->newInstance([], true)->setRawAttributes($row, true));
                $after = PermissionSnapshot::of($permission->newInstance([], true)->setRawAttributes([...$row, ...$permission->getAttributes()], true));
                $changed = array_values(array_filter(
                    array_keys($after),
                    static fn (string $key): bool => $key !== 'v' && $key !== 'key' && $before[$key] !== $after[$key],
                ));

                if ($changed !== []) {
                    Announcer::announce(fn (): PermissionUpdated => new PermissionUpdated($permission, $before, $after, $changed, actor: app(ActorResolver::class)->resolve(), operation: app(Operations::class)->current()));
                }
            });
        });

        static::deleted(function (Model $permission): void {
            app(Operations::class)->during(function () use ($permission): void {
                $invalidations = app(CacheInvalidations::class);

                $invalidations->settleCascade($permission);
                Announcer::announce(fn (): PermissionDeleted => new PermissionDeleted(
                    $permission,
                    actor: app(ActorResolver::class)->resolve(),
                    softDeleted: method_exists($permission, 'isForceDeleting') && $permission->isForceDeleting() === false,
                    operation: app(Operations::class)->current(),
                ));
                $invalidations->announceCascade($permission);
            });
        });

        /** @var WeakMap<Model, bool> $wasTrashed */
        $wasTrashed = new WeakMap;

        // By hand: only SoftDeletes defines static::restoring() and restored().
        // Eloquent fires restored after save() has run the updated hook's cache
        // marks, and also for a row that was not in the trash: restoring notes it.
        static::registerModelEvent('restoring', function (Model $permission) use ($wasTrashed): void {
            $wasTrashed[$permission] = method_exists($permission, 'trashed') && $permission->trashed() === true;
        });

        static::registerModelEvent('restored', function (Model $permission) use ($wasTrashed): void {
            $fromTrash = $wasTrashed[$permission] ?? false;
            unset($wasTrashed[$permission]);

            if (! $fromTrash) {
                return;
            }

            app(Operations::class)->during(function () use ($permission): void {
                Announcer::announce(fn (): PermissionRestored => new PermissionRestored(
                    $permission,
                    actor: app(ActorResolver::class)->resolve(),
                    operation: app(Operations::class)->current(),
                ));
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // No cast on entity_id: it must carry UUID/ULID keys untouched.
        return [
            'only_owned' => 'boolean',
            'options' => 'array',
        ];
    }

    protected function contextTableKey(): string
    {
        return 'permissions';
    }
}
