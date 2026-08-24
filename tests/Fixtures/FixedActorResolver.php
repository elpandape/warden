<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Contracts\ActorResolver;
use Illuminate\Database\Eloquent\Model;

final class FixedActorResolver implements ActorResolver
{
    public function resolve(): ?Model
    {
        return User::query()->firstWhere('name', 'Auditor');
    }
}
