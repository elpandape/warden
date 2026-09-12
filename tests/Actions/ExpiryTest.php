<?php

declare(strict_types=1);

use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BarePivot;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();
    withForeignKeys();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
    $this->moment = Carbon::parse('2026-12-31 23:59:59');
});

dataset('the plain rule', [
    'left orphaned by the chain' => [fn (): null => null],
    'kept by another holder' => [fn (): GrantsPermissions => app(Warden::class)->allow(User::query()->create(['name' => 'Grace']))->to('view', Account::class)],
]);

it('ends a grant at the moment given', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('publish', Account::class);

    expect(Grant::query()->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
});

it('ends a role assignment at the moment given, on the assignment and not the role', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    $assignment = AssignedRole::query()->sole();

    expect($assignment->getAttribute('expires_at')?->toDateTimeString())->toBe('2026-12-31 23:59:59')
        ->and($assignment->role()->sole()->getAttributes())->not->toHaveKey('expires_at');
});

it('lets the same role end on different days for different holders', function (): void {
    $other = User::query()->create(['name' => 'Grace']);

    $this->warden->assign('auditor')->until($this->moment)->to($this->user);
    $this->warden->assign('auditor')->to($other);

    expect(AssignedRole::query()->whereNotNull('expires_at')->count())->toBe(1)
        ->and(AssignedRole::query()->whereNull('expires_at')->count())->toBe(1);
});

it('refuses until() after the write it was meant to shape', function (): void {
    $grant = $this->warden->allow($this->user)->to('publish', Account::class);

    expect(fn (): mixed => $grant->until($this->moment))
        ->toThrow(ConfigurationException::class, 'Call until() before to()');
});

it('refuses until() after an assignment has executed', function (): void {
    $assign = $this->warden->assign('auditor')->to($this->user);

    expect(fn (): mixed => $assign->until($this->moment))
        ->toThrow(ConfigurationException::class, 'Call until() before to()');
});

it('refuses to let a forbid expire', function (): void {
    expect(fn (): mixed => $this->warden->forbid($this->user)->until($this->moment))
        ->toThrow(ConfigurationException::class, 'A forbid does not expire');
});

it('counts moving the date as a write, so the cache and the audit trail hear it', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('publish', Account::class);

    Event::fake([PermissionGranted::class, RoleAssigned::class]);

    $this->warden->allow($this->user)->until(Carbon::parse('2026-06-30 12:00:00'))->to('publish', Account::class);

    Event::assertDispatched(PermissionGranted::class);
    expect(Grant::query()->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-06-30 12:00:00');
});

it('counts moving an assignment date as a write too', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    Event::fake([PermissionGranted::class, RoleAssigned::class]);

    $this->warden->assign('auditor')->until(Carbon::parse('2026-06-30 12:00:00'))->to($this->user);

    Event::assertDispatched(RoleAssigned::class);
});

it('stays quiet when the date it was asked to write is the one already there', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('publish', Account::class);

    Event::fake([PermissionGranted::class, RoleAssigned::class]);

    $this->warden->allow($this->user)->until($this->moment)->to('publish', Account::class);

    Event::assertNotDispatched(PermissionGranted::class);
});

it('keeps the end date when a condition re-points the concession to a twin', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
});

it('moves the end date of a twin when the chain runs again with a new one', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->until(Carbon::parse('2026-10-31 12:00:00'))->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-10-31 12:00:00');
})->with('the plain rule');

it('lifts the end date of a twin when the chain runs again with until(null)', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->until(null)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at'))->toBeNull();
})->with('the plain rule');

it('keeps the end date of a twin when the chain runs again without until()', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
})->with('the plain rule');

it('keeps the end date of the old condition when the chain edits it without until()', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Globex');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
})->with('the plain rule');

it('leaves the twin without an end when the plain rule it replaces had none', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at'))->toBeNull();
})->with('the plain rule');

it('lets the later end date win when the rows a twin replaces disagree', function (Closure $plainRule): void {
    $plainRule();

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->until(Carbon::parse('2027-06-30 12:00:00'))->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->whereMorphedTo('entity', $this->user)->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2027-06-30 12:00:00');
})->with('the plain rule');

it('keeps the end date of a twin when the grant model casts no dates', function (): void {
    config()->set('warden.models.grant', BarePivot::class);
    app()->forgetInstance(Context::class);

    $this->warden->allow($this->user)->until($this->moment)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    expect(Grant::query()->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
});

it('keeps the end date of the rows a sync keeps', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    $this->warden->sync($this->user)->roles(['auditor', 'editor']);

    $kept = AssignedRole::query()->whereNotNull('expires_at')->sole();

    expect($kept->getAttribute('expires_at')?->toDateTimeString())->toBe('2026-12-31 23:59:59')
        ->and(AssignedRole::query()->count())->toBe(2);
});

it('leaves an existing end date alone when the write says nothing about time', function (): void {
    $this->warden->assign('auditor')->until($this->moment)->to($this->user);

    $this->warden->assign('auditor')->to($this->user);

    expect(AssignedRole::query()->sole()->getAttribute('expires_at')?->toDateTimeString())
        ->toBe('2026-12-31 23:59:59');
});

it('lifts an end date when the write says so', function (): void {
    $this->warden->allow($this->user)->until($this->moment)->to('publish', Account::class);

    $this->warden->allow($this->user)->until(null)->to('publish', Account::class);

    expect(Grant::query()->sole()->getAttribute('expires_at'))->toBeNull();
});
