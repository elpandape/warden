<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Testing;

use Illuminate\Database\Migrations\Migration;

/**
 * A loadable entry point to warden's schema.
 *
 * The migration ships as a publishable stub, which an application installs but
 * a dependent package cannot require without resolving a vendor path of its
 * own. Both methods honour warden.tables and warden.connection, because the
 * stub reads them itself.
 */
final class Schema
{
    public static function up(): void
    {
        // Laravel leaves up()/down() off the base class: the stub defines both.
        self::migration()->up(); // @phpstan-ignore method.notFound
    }

    public static function down(): void
    {
        self::migration()->down(); // @phpstan-ignore method.notFound
    }

    private static function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../../database/migrations/create_warden_tables.php.stub';

        return $migration;
    }
}
