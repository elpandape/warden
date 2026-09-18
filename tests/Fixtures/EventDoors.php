<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Closure;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;

final class EventDoors
{
    use DispatchesEvents;

    public function __construct(bool $silentEvents = false)
    {
        $this->silentEvents = $silentEvents;
    }

    /**
     * @param  Closure(): object  $build
     */
    public function permits(Closure $build): bool
    {
        return $this->eventPermits($build);
    }

    /**
     * @param  Closure(): object  $build
     */
    public function announce(Closure $build): void
    {
        $this->dispatchWardenEvent($build);
    }
}
