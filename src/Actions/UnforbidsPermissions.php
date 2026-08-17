<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

class UnforbidsPermissions extends RevokesPermissions
{
    protected bool $forbidden = true;
}
