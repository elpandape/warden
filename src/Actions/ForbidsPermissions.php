<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

class ForbidsPermissions extends GrantsPermissions
{
    protected bool $forbidding = true;
}
