<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use Illuminate\Support\Facades\Event;

/**
 * The one door every post-write event leaves through.
 *
 * @internal
 */
final class Announcer
{
    public static function announce(object $event): void
    {
        if (! Config::eventsEnabled()) {
            return;
        }

        // A listener that asks can() is answered after the write it hears
        // about, never from a version the open boundary has not bumped yet.
        app(CacheInvalidations::class)->flush();

        Event::dispatch($event);
    }
}
