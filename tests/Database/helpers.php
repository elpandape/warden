<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function dropBouncerTables(): void
{
    // Children first: real databases enforce the foreign keys.
    foreach (['grants', 'assigned_roles', 'roles', 'permissions', 'users', 'accounts'] as $table) {
        Schema::dropIfExists($table);
    }
}

function migrateBouncerTables(): Migration
{
    dropBouncerTables();

    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_bouncer_tables.php.stub';
    $migration->up();

    foreach (['users', 'accounts'] as $table) {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('name')->nullable();
            $blueprint->timestamps();
        });
    }

    return $migration;
}
