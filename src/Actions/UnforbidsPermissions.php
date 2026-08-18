<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

class UnforbidsPermissions extends RevokesPermissions
{
    protected bool $forbidden = true;
}
