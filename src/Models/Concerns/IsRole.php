<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Concerns\HasPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Models\Relations\ReadOnlyBelongsToMany;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Titles\RoleTitle;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Event;

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

        return $relation->wherePivot('entity_type', $this->getMorphClass());
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
            if (Config::eventsEnabled()) {
                Event::dispatch(new RoleCreated($role));
            }
        });

        static::deleted(function (Model $role): void {
            $invalidations = app(CacheInvalidations::class);
            $invalidations->settleCascade($role);

            if (Config::eventsEnabled()) {
                Event::dispatch(new RoleDeleted($role));
            }

            $invalidations->announceCascade($role);
        });
    }

    protected function contextTableKey(): string
    {
        return 'roles';
    }
}
