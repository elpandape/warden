<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BoolCastAccount;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\storeConditionRefusedSince3;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
});

it('reports a stored condition the write path would refuse today', function (): void {
    $this->warden->forbid($this->user)->to('view', Account::class);
    storeConditionRefusedSince3();

    $this->artisan('warden:doctor')
        ->expectsOutputToContain('1 stored condition(s) can never be true.')
        ->expectsOutputToContain('[user_id] is compared as a boolean')
        ->assertExitCode(1);
});

it('says the forbid beneath the broken rule never fires', function (): void {
    $this->warden->forbid($this->user)->to('view', Account::class);
    storeConditionRefusedSince3();

    $this->artisan('warden:doctor')
        ->expectsOutputToContain('forbid x1, grant x0')
        ->expectsOutputToContain('the grant beneath it stays live')
        ->assertExitCode(1);
});

it('counts both polarities of the shared catalog row', function (): void {
    $other = User::query()->create(['name' => 'Grace']);
    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($other)->to('view', Account::class);
    storeConditionRefusedSince3();

    $this->artisan('warden:doctor')
        ->expectsOutputToContain('forbid x1, grant x1')
        ->assertExitCode(1);
});

it('passes a catalog whose every rule can still hold', function (): void {
    $this->warden->allow($this->user)->to('view', BoolCastAccount::class)->where('user_id', true);

    $this->artisan('warden:doctor')
        ->expectsOutputToContain('every rule in the catalog is satisfiable')
        ->assertExitCode(0);
});

it('leaves the catalog exactly as it found it', function (): void {
    $this->warden->forbid($this->user)->to('view', Account::class);
    storeConditionRefusedSince3();
    $before = DB::table('permissions')->orderBy('id')->get()->toJson();

    $this->artisan('warden:doctor')->assertExitCode(1);

    expect(DB::table('permissions')->orderBy('id')->get()->toJson())->toBe($before);
});

it('skips a wildcard permission, which names no model to ask', function (): void {
    $this->warden->forbid($this->user)->everything();
    DB::table('permissions')->update([
        'options' => '{"v": 1, "g": {"t": "group", "i": [["and", {"t": "value", "c": "user_id", "o": "=", "v": true}]]}}',
    ]);

    $this->artisan('warden:doctor')->assertExitCode(0);
});

it('skips an entity_type that resolves to no model class', function (): void {
    Permission::query()->create([
        'name' => 'view',
        'entity_type' => 'App\\Models\\Vanished',
        'options' => '{"v": 1, "g": {"t": "group", "i": [["and", {"t": "value", "c": "user_id", "o": "=", "v": true}]]}}',
    ]);

    $this->artisan('warden:doctor')->assertExitCode(0);
});

it('skips a row whose stored conditions cannot be decoded', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);

    // Valid JSON of an unknown shape, not malformed text: a json column on
    // MySQL and Postgres rejects the latter outright, so only sqlite would
    // ever hold it and the test would prove nothing on the other two.
    DB::table('permissions')->whereNotNull('entity_type')->update(['options' => '{"v": 99, "g": null}']);

    $this->artisan('warden:doctor')->assertExitCode(0);
});

it('skips an entity-less permission, which is never checked against an instance', function (): void {
    Permission::query()->create([
        'name' => 'view',
        'entity_type' => null,
        'options' => '{"v": 1, "g": {"t": "group", "i": [["and", {"t": "value", "c": "user_id", "o": "=", "v": true}]]}}',
    ]);

    $this->artisan('warden:doctor')->assertExitCode(0);
});
