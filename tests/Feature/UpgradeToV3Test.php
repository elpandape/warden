<?php

declare(strict_types=1);

use ElPandaPe\Warden\Testing\Schema as WardenSchema;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\migrateWithoutExpiry;
use function ElPandaPe\Warden\Tests\Database\wardenUpgradeToV3Migration;

it('adds the expiry column to both pivots, never to one', function (): void {
    migrateWithoutExpiry();

    expect(Schema::hasColumn('assigned_roles', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('grants', 'expires_at'))->toBeFalse();

    WardenSchema::upgradeToV3();

    expect(Schema::hasColumn('assigned_roles', 'expires_at'))->toBeTrue()
        ->and(Schema::hasColumn('grants', 'expires_at'))->toBeTrue();
});

it('runs again over a schema that already has the column', function (): void {
    migrateWithoutExpiry();

    WardenSchema::upgradeToV3();
    WardenSchema::upgradeToV3();

    expect(Schema::hasColumn('assigned_roles', 'expires_at'))->toBeTrue();
});

it('leaves the rows it finds untouched', function (): void {
    migrateWithoutExpiry();
    $user = User::query()->create(['name' => 'Ada']);
    app(Warden::class)->assign('auditor')->to($user);

    WardenSchema::upgradeToV3();

    expect(DB::table('assigned_roles')->count())->toBe(1)
        ->and(DB::table('assigned_roles')->value('expires_at'))->toBeNull();
});

it('keeps the end date out of the tuple that identifies a row', function (): void {
    migrateWardenTables();

    foreach (['assigned_roles', 'grants'] as $table) {
        $unique = collect(Schema::getIndexes($table))->firstWhere('unique', true);

        expect($unique['columns'] ?? [])->not->toContain('expires_at');
    }
});

it('hydrates the end date through the relation, not just the table', function (): void {
    migrateWardenTables();
    $user = User::query()->create(['name' => 'Ada']);
    app(Warden::class)->assign('auditor')->to($user);
    DB::table('assigned_roles')->update(['expires_at' => '2026-12-31 23:59:59']);

    $pivot = $user->roles()->first()?->getRelationValue('pivot');

    expect($pivot?->getAttribute('expires_at'))->toBe('2026-12-31 23:59:59');
});

it('drops the column back off both pivots', function (): void {
    migrateWardenTables();

    wardenUpgradeToV3Migration()->down();

    expect(Schema::hasColumn('assigned_roles', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('grants', 'expires_at'))->toBeFalse();
});

it('rolls back over a schema that never had the column', function (): void {
    migrateWithoutExpiry();

    wardenUpgradeToV3Migration()->down();

    expect(Schema::hasColumn('grants', 'expires_at'))->toBeFalse();
});
