<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events\Concerns;

use ElPandaPe\Warden\Support\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

trait DispatchesEvents
{
    /**
     * Sync suppresses the per-action events of the writes it delegates:
     * its own diffed events already tell the whole story.
     */
    protected bool $silentEvents = false;

    private function dispatchWardenEvent(object $event): void
    {
        if (Config::eventsEnabled() && ! $this->silentEvents) {
            Event::dispatch($event);
        }
    }

    /**
     * Cancellable pre-action gate: false from any listener aborts the write.
     */
    private function eventPermits(object $event): bool
    {
        if (! Config::eventsEnabled() || ! Config::cancellableEvents() || $this->silentEvents) {
            return true;
        }

        // The stub types until() as array|null, but a listener's literal
        // false does come through at runtime: that is the whole contract.
        /** @phpstan-ignore notIdentical.alwaysTrue */
        return app(Dispatcher::class)->until($event) !== false;
    }
}
