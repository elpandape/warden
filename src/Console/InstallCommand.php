<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'warden:install {--migrate : Run the migrations after publishing}';

    protected $description = 'Publish the Warden config and migration';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'warden-config']);

        // The migration filename is timestamped: re-running must not stack a
        // duplicate that breaks the next migrate.
        $published = glob(database_path('migrations/*_create_warden_tables.php')) ?: [];

        if ($published === []) {
            $this->call('vendor:publish', ['--tag' => 'warden-migrations']);
        } else {
            $this->components->warn('Migration already published; skipping.');
        }

        if ((bool) $this->option('migrate')) {
            $this->call('migrate'); // @codeCoverageIgnore
        }

        $this->components->info('Warden is ready. Add the HasRolesAndPermissions concern to your authority models.');

        return self::SUCCESS;
    }
}
