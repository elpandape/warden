<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models\Concerns;

use ElPandaPe\Bouncer\Concerns\HasPermissions;
use ElPandaPe\Bouncer\Support\Config;
use ElPandaPe\Bouncer\Support\Titles\RoleTitle;
use ElPandaPe\Bouncer\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

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
    }

    protected function contextTableKey(): string
    {
        return 'roles';
    }
}
