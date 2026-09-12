<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use ElPandaPe\Warden\Tenancy\AppliesPivotTenancy;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Event;

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
            if (Config::eventsEnabled()) {
                Event::dispatch(new PermissionCreated($permission));
            }
        });

        static::deleted(function (Model $permission): void {
            $invalidations = app(CacheInvalidations::class);
            $invalidations->settleCascade($permission);

            if (Config::eventsEnabled()) {
                Event::dispatch(new PermissionDeleted($permission));
            }

            $invalidations->announceCascade($permission);
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
