<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events\Concerns;

use Closure;
use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Support\Operations;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

trait DispatchesEvents
{
    /**
     * Sync suppresses the per-action events of the writes it delegates:
     * its own diffed events already tell the whole story.
     */
    protected bool $silentEvents = false;

    /**
     * @param  Closure(): object  $build
     */
    private function dispatchWardenEvent(Closure $build): void
    {
        if ($this->announces()) {
            Announcer::announce($build);
        }
    }

    /**
     * Cancellable pre-action gate: false from any listener aborts the write.
     *
     * @param  Closure(): object  $build
     */
    private function eventPermits(Closure $build): bool
    {
        if (! $this->announces() || ! Config::cancellableEvents()) {
            return true;
        }

        // The stub types until() as array|null, but a listener's literal
        // false does come through at runtime: that is the whole contract.
        /** @phpstan-ignore notIdentical.alwaysTrue */
        return app(Dispatcher::class)->until($build()) !== false;
    }

    private function announces(): bool
    {
        return ! $this->silentEvents && Config::eventsEnabled();
    }

    /**
     * Who is performing this write, as the application defines it.
     */
    private function actor(): ?Model
    {
        return app(ActorResolver::class)->resolve();
    }

    private function operation(): ?string
    {
        return app(Operations::class)->current();
    }
}
