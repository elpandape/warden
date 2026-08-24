<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ActorResolver
{
    /**
     * Resolve who is performing the current write, if anyone.
     *
     * The default reads the authenticated user. Applications override this for
     * queues, console commands and impersonation, where "current actor" is a
     * question only they can answer.
     */
    public function resolve(): ?Model;
}
