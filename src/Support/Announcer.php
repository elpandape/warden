<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

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

        Event::dispatch($event);
    }
}
