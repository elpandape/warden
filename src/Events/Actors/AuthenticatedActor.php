<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events\Actors;

use ElPandaPe\Warden\Contracts\ActorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuthenticatedActor implements ActorResolver
{
    public function resolve(): ?Model
    {
        $user = Auth::user();

        return $user instanceof Model ? $user : null;
    }
}
