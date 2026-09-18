<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use Closure;
use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Contracts\ActorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * The one door every post-write event leaves through.
 *
 * @internal
 */
final class Announcer
{
    /**
     * Built only once it is certain to go out, so a custom actor resolver is
     * never asked about a write that announces nothing.
     *
     * @param  Closure(): object  $build
     */
    public static function announce(Closure $build): void
    {
        if (! Config::eventsEnabled()) {
            return;
        }

        // A listener that asks can() is answered after the write it hears
        // about, never from a version the open boundary has not bumped yet.
        app(CacheInvalidations::class)->flush();

        Event::dispatch($build());
    }

    /**
     * One actor for every event a call may announce: the resolver is asked
     * when the first of them is built, and not at all when none is.
     *
     * @return Closure(): ?Model
     */
    public static function actorOnce(): Closure
    {
        $asked = false;
        $actor = null;

        return static function () use (&$asked, &$actor): ?Model {
            if (! $asked) {
                $actor = app(ActorResolver::class)->resolve();
                $asked = true;
            }

            return $actor;
        };
    }
}
