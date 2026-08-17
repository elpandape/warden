<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models\Concerns;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Events\PermissionCreated;
use ElPandaPe\Bouncer\Events\PermissionDeleted;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Support\Titles\PermissionTitle;
use ElPandaPe\Bouncer\Tenancy\AppliesPivotTenancy;
use ElPandaPe\Bouncer\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Event;

trait IsPermission
{
    use AppliesPivotTenancy;
    use BelongsToTenant;
    use ResolvesContext;

    /**
     * @return MorphToMany<\ElPandaPe\Bouncer\Models\Role, $this, Grant>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->roleClass();
        $grant = $context->grantClass();

        $relation = $this
            ->morphedByMany($role, 'entity', $context->table('grants'), 'permission_id')
            ->using($grant)
            ->withPivot(['forbidden', 'scope']);

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

        // Lifecycle events fire at the model layer: every creation path counts.
        static::created(function (Model $permission): void {
            if (Config::eventsEnabled()) {
                Event::dispatch(new PermissionCreated($permission));
            }
        });

        static::deleted(function (Model $permission): void {
            if (Config::eventsEnabled()) {
                Event::dispatch(new PermissionDeleted($permission));
            }
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
