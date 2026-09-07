<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
    $this->account = Account::query()->create(['name' => 'Acme']);
    $this->moment = Carbon::parse('2026-12-31 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('authorizes right up to the moment named', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment->copy()->subSecond());

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('stops authorizing at the moment named, not a tick later', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('drops the permissions a role lent once the assignment expires', function (): void {
    $this->warden->allow('auditor')->to('view', Account::class);
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('keeps a query and a check agreeing over expired rows', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('leaves an expired grant out of the listing, so no menu outlives the check', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect($this->user->getPermissions())->toBeEmpty();
});

it('says no matching grant once it expired, never a stale cause', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect($this->warden->explain($this->user, 'view', $this->account)->cause)
        ->toBe(Cause::NoMatchingGrant);
});

it('expires inside a payload built while the grant was still alive', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);

    Carbon::setTestNow($this->moment->copy()->subHour());
    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    // No refresh(): the payload cached a second ago must not outlive the row.
    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('ends a role-borrowed grant at whichever of the two ends first', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);
    $this->warden->allow('auditor')->until($this->moment->copy()->addYear())->to('view', Account::class);
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('keeps the later of two dates when the same permission arrives twice', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);
    $this->warden->allow('auditor')->until($this->moment->copy()->addYear())->to('view', Account::class);
    $this->warden->assign('auditor')->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('lets an endless path outlive a dated one for the same permission', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class);
    $this->warden->allow('auditor')->to('view', Account::class);
    $this->warden->assign('auditor')->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addYear());
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});
