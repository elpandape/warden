<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BarePivot;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

it('reads the end date a row casts as an immutable moment in the app zone', function (): void {
    app(Warden::class)
        ->allow(User::query()->create(['name' => 'Ada']))
        ->until(Carbon::parse('2026-12-31 23:59:59', 'Europe/Madrid'))
        ->to('publish', Account::class);

    $expiresAt = Expiry::of(Grant::query()->sole());

    expect($expiresAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($expiresAt?->toDateTimeString())->toBe('2026-12-31 23:59:59')
        ->and($expiresAt?->getTimezone()->getName())->toBe('UTC');
});

it('reads the end date a pivot without a cast holds as text', function (): void {
    $row = new BarePivot;
    $row->setRawAttributes(['expires_at' => '2026-12-31 23:59:59']);

    expect(Expiry::of($row)?->toDateTimeString())->toBe('2026-12-31 23:59:59');
});

it('reads a date a pivot without a cast holds unsaved, by the wall time it will store', function (): void {
    $row = new BarePivot;
    $row->setAttribute('expires_at', Carbon::parse('2026-12-31 23:59:59', 'Europe/Madrid'));

    expect(Expiry::of($row)?->toDateTimeString())->toBe('2026-12-31 23:59:59')
        ->and(Expiry::of($row)?->getTimezone()->getName())->toBe('UTC');
});

it('reads a row with no end date as null', function (): void {
    $row = new BarePivot;

    expect(Expiry::of($row))->toBeNull();

    $row->setRawAttributes(['expires_at' => null]);

    expect(Expiry::of($row))->toBeNull();

    $row->setRawAttributes(['expires_at' => '']);

    expect(Expiry::of($row))->toBeNull();
});

it('reads the zero date a lenient MySQL keeps as long gone, without throwing', function (): void {
    $row = new BarePivot;
    $row->setRawAttributes(['expires_at' => '0000-00-00 00:00:00']);

    $expiresAt = Expiry::of($row);

    expect($expiresAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($expiresAt?->isPast())->toBeTrue();
});
