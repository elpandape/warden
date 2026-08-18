<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Console;

use ElPandaPe\Bouncer\Bouncer;
use Illuminate\Console\Command;

final class CacheResetCommand extends Command
{
    protected $signature = 'bouncer:cache-reset';

    protected $description = 'Invalidate every cached authorization payload (an O(1) version bump)';

    public function handle(Bouncer $bouncer): int
    {
        $bouncer->refresh();

        $this->components->info('Bouncer cache invalidated.');

        return self::SUCCESS;
    }
}
