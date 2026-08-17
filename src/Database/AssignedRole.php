<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database;

use ElPandaPe\Bouncer\Database\Concerns\ResolvesContext;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class AssignedRole extends MorphPivot
{
    use ResolvesContext;

    public $incrementing = true;

    public function usesTimestamps(): bool
    {
        return Config::pivotTimestamps();
    }

    protected function contextTableKey(): string
    {
        return 'assigned_roles';
    }
}
