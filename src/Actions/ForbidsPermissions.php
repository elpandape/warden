<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use DateTimeInterface;
use ElPandaPe\Warden\Exceptions\ConfigurationException;

class ForbidsPermissions extends GrantsPermissions
{
    protected bool $forbidding = true;

    /**
     * A prohibition that expires by clock would turn "a forbid beats every
     * grant" into "until Tuesday", and the grant beneath it is still there
     * waiting. Lift it deliberately instead.
     */
    public function until(?DateTimeInterface $moment): static
    {
        throw new ConfigurationException('A forbid does not expire: lift it with unforbid().');
    }
}
