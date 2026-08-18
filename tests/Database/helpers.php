<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function dropWardenTables(): void
{
    // Children first: real databases enforce the foreign keys.
    foreach (['grants', 'assigned_roles', 'roles', 'permissions', 'users', 'accounts'] as $table) {
        Schema::dropIfExists($table);
    }
}

function migrateWardenTables(): Migration
{
    dropWardenTables();

    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_warden_tables.php.stub';
    $migration->up();

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

    return $migration;
}
