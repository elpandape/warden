<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\nestRole;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
    $this->account = Account::query()->create(['name' => 'Acme']);
});

it('lends nothing through a nested role while the feature is off', function (): void {
    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('lends the inner role grants once nesting is on', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('reaches deeper than one hop', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'reviewer');
    nestRole('reviewer', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('answers is() the way it answers can()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect($this->warden->is($this->user)->an('auditor'))->toBeTrue();
});

it('answers whereIs() the way it answers is()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(User::query()->whereIs('auditor')->count())->toBe(1);
});

it('terminates on a cycle instead of recursing forever', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    nestRole('editor', 'auditor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('keeps a query and a check agreeing over a nested role', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(1)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('answers the same through the cached engine', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('drops the nested reach the moment the flag goes off, without waiting for the cache', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    config()->set('warden.roles.nested', false);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('exposes the nesting as a relation on the role itself', function (): void {
    nestRole('auditor', 'editor');

    $editor = Role::query()->where('name', 'editor')->sole();

    expect($editor->nestedRoles()->pluck('name')->all())->toBe(['auditor']);
});

it('walks a path whose links are both dated and still live', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    $editor = Role::query()->firstOrCreate(['name' => 'editor']);
    $this->warden->assign('auditor')->until(Carbon::parse('2028-01-01 00:00:00'))->to($editor);
    $this->warden->assign('editor')->until(Carbon::parse('2027-01-01 00:00:00'))->to($this->user);

    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    // Past the earlier of the two, the reach is gone even though the inner
    // edge runs another year.
    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();

    Carbon::setTestNow();
});

it('answers whereIs() for a role nobody defined', function (): void {
    config()->set('warden.roles.nested', true);

    expect(User::query()->whereIs('ghost')->count())->toBe(0);
});

it('ends a dated grant borrowed through a dated assignment at the earlier one', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->until(Carbon::parse('2028-01-01 00:00:00'))->to('view', Account::class);
    $this->warden->assign('auditor')->until(Carbon::parse('2027-01-01 00:00:00'))->to($this->user);

    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    // The assignment ends first, so the grant it lent ends with it.
    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();

    Carbon::setTestNow();
});
