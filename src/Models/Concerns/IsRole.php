<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models\Concerns;

use ElPandaPe\Warden\Concerns\HasPermissions;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Titles\RoleTitle;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

trait IsRole
{
    use BelongsToTenant;
    use HasPermissions;
    use ResolvesContext;

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
            if (Config::eventsEnabled()) {
                Event::dispatch(new RoleDeleted($role));
            }
        });
    }

    protected function contextTableKey(): string
    {
        return 'roles';
    }
}
