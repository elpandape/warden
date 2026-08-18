<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Warden;
use Illuminate\Console\Command;

final class CacheResetCommand extends Command
{
    protected $signature = 'warden:cache-reset';

    protected $description = 'Invalidate every cached authorization payload (an O(1) version bump)';

    public function handle(Warden $warden): int
    {
        $warden->refresh();

        $this->components->info('Warden cache invalidated.');

        return self::SUCCESS;
    }
}
