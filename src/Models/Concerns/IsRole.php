<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models\Concerns;

use ElPandaPe\Bouncer\Concerns\HasPermissions;
use ElPandaPe\Bouncer\Events\RoleCreated;
use ElPandaPe\Bouncer\Events\RoleDeleted;
use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Support\Titles\RoleTitle;
use ElPandaPe\Bouncer\Tenancy\BelongsToTenant;
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
