<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

class ForbidsPermissions extends GrantsPermissions
{
    protected bool $forbidding = true;
}
