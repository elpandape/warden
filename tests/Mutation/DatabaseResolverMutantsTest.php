<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    config()->set('warden.cache.enabled', false);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('authorizes through roles whose keys are strings', function (): void {
    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->to($this->user);

    $account = Account::query()->create(['name' => 'Acme']);

    // Forge the non-numeric key shape a string-keyed role model produces.
    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('assigned_roles')->update(['role_id' => 'role-uuid']);
    DB::table('grants')->update(['entity_id' => 'role-uuid']);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $account))->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

it('collects keys from every unrestricted role assignment', function (): void {
    $this->warden->allow('viewer')->to('view-reports');
    $this->warden->allow('editor')->to('edit-reports');

    $this->warden->assign('viewer')->to($this->user);
    $this->warden->assign('editor')->to($this->user);

    $gate = Gate::forUser($this->user);

    expect($gate->allows('view-reports'))->toBeTrue()
        ->and($gate->allows('edit-reports'))->toBeTrue();
});

it('keeps scanning assignments after a half-written restriction row', function (): void {
    $orgOne = Account::query()->create(['name' => 'Org One'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($orgOne)->to($this->user);
    $this->warden->assign('editor')->to($this->user);

    // Corrupt the first (restricted) row into the half-written shape.
    AssignedRole::query()->whereNotNull('restricted_to_type')->update(['restricted_to_id' => null]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue();
});

it('does not let simple grants satisfy a literal star check', function (): void {
    $this->warden->allow($this->user)->to('audit');

    $gate = Gate::forUser($this->user);

    expect($gate->allows('audit'))->toBeTrue()
        ->and($gate->allows('audit', '*'))->toBeFalse();
});
