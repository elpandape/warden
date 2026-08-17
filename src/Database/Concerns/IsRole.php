<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Database\Titles\RoleTitle;
use ElPandaPe\Bouncer\Support\Config;
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
