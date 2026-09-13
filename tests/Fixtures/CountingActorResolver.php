<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Contracts\ActorResolver;
use Illuminate\Database\Eloquent\Model;

final class CountingActorResolver implements ActorResolver
{
    public int $calls = 0;

    public function resolve(): ?Model
    {
        $this->calls++;

        return null;
    }
}
