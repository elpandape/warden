<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Database;

use ElPandaPe\Warden\Testing\Schema as WardenSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function wardenMigration(): Migration
{
    return require __DIR__.'/../../database/migrations/create_warden_tables.php.stub';
}

function wardenUpgradeToV3Migration(): Migration
{
    return require __DIR__.'/../../database/migrations/upgrade_warden_to_v3.php.stub';
}

function dropWardenTables(): void
{
    // Children first: real databases enforce the foreign keys.
    foreach (['grants', 'assigned_roles', 'roles', 'permissions', 'users', 'accounts'] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * The testing connection runs SQLite with foreign keys off, so a catalog
 * delete cascades nowhere unless a test turns them on. MySQL and Postgres
 * always enforce them.
 */
function withForeignKeys(): void
{
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = ON');
    }
}

function migrateWardenTables(): void
{
    dropWardenTables();

    WardenSchema::up();

    foreach (['users', 'accounts'] as $table) {
        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();
            $blueprint->string('name')->nullable();

            if ($table === 'accounts') {
                $blueprint->unsignedBigInteger('user_id')->nullable();
                $blueprint->unsignedBigInteger('account_id')->nullable();
                $blueprint->unsignedBigInteger('owner_id')->nullable();
            }

            $blueprint->timestamps();
        });
    }
}

function migrateWithoutExpiry(): void
{
    migrateWardenTables();

    foreach (['assigned_roles', 'grants'] as $table) {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['expires_at']);
            $blueprint->dropColumn('expires_at');
        });
    }
}

function migrateLegacyCatalog(): void
{
    migrateWardenTables();

    Schema::table('permissions', function (Blueprint $blueprint): void {
        $blueprint->dropUnique('permissions_identity_unique');
        $blueprint->dropColumn('identity_key');
    });
}

/**
 * Authorities on a second connection, the way the landlord vs tenant recipe
 * lays them out: warden's tables on one database, the users on another.
 */
function migrateRemoteUsers(?string $textKeyCollation = null): void
{
    config()->set('database.connections.remote', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    Schema::connection('remote')->create('users', function (Blueprint $blueprint) use ($textKeyCollation): void {
        if ($textKeyCollation === null) {
            $blueprint->id();
        } else {
            $blueprint->string('id')->collation($textKeyCollation)->primary();
        }

        $blueprint->string('name')->nullable();
        $blueprint->timestamps();
    });
}
