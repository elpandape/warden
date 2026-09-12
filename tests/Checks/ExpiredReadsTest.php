<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Exceptions\UnauthorizedException;
use ElPandaPe\Warden\Http\Middleware\RequiresRole;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BareAssignedRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\nestRole;
use function ElPandaPe\Warden\Tests\requestAs;

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

it('stops answering is() once the assignment expired', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect($this->user->isAn('auditor'))->toBeFalse()
        ->and($this->user->isNotAn('auditor'))->toBeTrue()
        ->and($this->user->isAll('auditor'))->toBeFalse()
        ->and($this->warden->is($this->user)->an('auditor'))->toBeFalse()
        ->and($this->warden->is($this->user)->all('auditor'))->toBeFalse();
});

it('stops holding a role at the moment named, not a tick later', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->subSecond());

    expect($this->user->isAn('auditor'))->toBeTrue();

    Carbon::setTestNow($this->moment);

    expect($this->user->isAn('auditor'))->toBeFalse();
});

it('keeps listing an expired assignment in the roles relation', function (): void {
    Carbon::setTestNow($this->moment->copy()->subDay());
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect($this->user->roles()->pluck('name')->all())->toContain('auditor')
        ->and($this->user->isA('auditor'))->toBeFalse();
});

it('drops an expired assignment from the eager-loaded roles too', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);
    $this->warden->assign('editor')->to($this->user);
    $this->user->load('roles');

    Carbon::setTestNow($this->moment->copy()->subSecond());

    expect($this->user->isAn('auditor'))->toBeTrue()
        ->and($this->user->isAll('auditor', 'editor'))->toBeTrue();

    Carbon::setTestNow($this->moment);

    expect($this->user->isAn('auditor'))->toBeFalse()
        ->and($this->user->isAn('editor'))->toBeTrue()
        ->and($this->user->isAll('auditor', 'editor'))->toBeFalse();
});

it('reads the end date off a swapped-in pivot that has no datetime cast', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    config()->set('warden.models.assigned_role', BareAssignedRole::class);
    app()->forgetInstance(Context::class);
    $this->user->load('roles');

    Carbon::setTestNow($this->moment);

    expect($this->user->isAn('auditor'))->toBeFalse();
});

it('leaves an expired assignment out of whereIs(), whereIsAll() and whereIsNot()', function (): void {
    $grace = User::query()->create(['name' => 'Grace']);
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);
    $this->warden->assign('auditor')->to($grace);

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect(User::query()->whereIs('auditor')->pluck('name')->all())->toBe(['Grace'])
        ->and(User::query()->whereIsAll('auditor')->pluck('name')->all())->toBe(['Grace'])
        ->and(User::query()->whereIsNot('auditor')->pluck('name')->all())->toBe(['Ada']);
});

it('turns an expired role away at the warden.role middleware', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);
    $middleware = new RequiresRole;

    Carbon::setTestNow($this->moment->copy()->subSecond());

    expect($middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'auditor')->getContent())
        ->toBe('ok');

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect(fn () => $middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'auditor'))
        ->toThrow(UnauthorizedException::class);
});

it('stops answering isAll() and the role scopes for an expired assignment with nesting on', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect($this->user->isAn('auditor'))->toBeFalse()
        ->and($this->user->isAll('auditor'))->toBeFalse()
        ->and(User::query()->whereIs('auditor')->count())->toBe(0)
        ->and(User::query()->whereIsAll('auditor')->count())->toBe(0)
        ->and(User::query()->whereIsNot('auditor')->count())->toBe(1);
});

it('stops reaching a nested role through an expired assignment', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->until($this->moment)->to($this->user);

    Carbon::setTestNow($this->moment->copy()->addSecond());

    expect($this->user->isAn('auditor'))->toBeFalse()
        ->and(User::query()->whereIs('auditor')->count())->toBe(0);
});
