<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\nestRole;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('explains direct grants', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    $why = $this->warden->explain($this->user, 'edit-site');

    expect($why->allowed())->toBeTrue()
        ->and($why->cause)->toBe(Cause::GrantedDirectly)
        ->and($why->permission?->getAttribute('name'))->toBe('edit-site')
        ->and((string) $why)->toBe('Granted by permission [edit-site], held directly.');
});

it('explains grants that arrive through a role', function (): void {
    $this->warden->allow('admin')->to('audit');
    $this->warden->assign('admin')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('admin')
        ->and((string) $why)->toBe('Granted by permission [audit] via role [admin].');
});

it('explains everyone-grants and explicit forbids', function (): void {
    $account = Account::query()->create(['name' => 'A'])->refresh();

    $this->warden->allowEveryone()->to('browse');
    $this->warden->allow($this->user)->to('edit', $account);
    $this->warden->forbid($this->user)->to('edit', $account);

    expect($this->warden->explain($this->user, 'browse')->cause)->toBe(Cause::GrantedToEveryone)
        ->and($this->warden->explain($this->user, 'edit', $account)->cause)->toBe(Cause::ForbiddenDirectly)
        ->and((string) $this->warden->explain($this->user, 'edit', $account))
        ->toContain('Explicitly forbidden');
});

it('explains forbids through roles and to everyone', function (): void {
    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbid('banned')->to('publish');
    $this->warden->assign('banned')->to($this->user);

    expect($this->warden->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($this->warden->explain($this->user, 'publish')->role?->getAttribute('name'))->toBe('banned');

    $this->warden->retract('banned')->from($this->user);
    $this->warden->forbidEveryone()->to('publish');

    expect($this->warden->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenToEveryone);
});

it('explains abstentions', function (): void {
    $why = $this->warden->explain($this->user, 'never-granted');

    expect($why->allowed())->toBeFalse()
        ->and($why->cause)->toBe(Cause::NoMatchingGrant)
        ->and($why->permission)->toBeNull()
        ->and((string) $why)->toContain('app policies decide')
        ->and($this->warden->explain($this->user, 'edit', 'not-a-class')->cause)->toBe(Cause::NotApplicable);
});

it('diagnoses from live rows even when the cache is stale', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow($this->user)->to('edit-site');

    expect($this->user->can('edit-site'))->toBeTrue();

    ElPandaPe\Warden\Models\Grant::query()->withoutGlobalScopes()->delete();

    // The cached check still says yes; the explanation tells the truth.
    expect($this->user->can('edit-site'))->toBeTrue()
        ->and($this->warden->explain($this->user, 'edit-site')->cause)->toBe(Cause::NoMatchingGrant);
});

it('accepts enums and hydrates the decisive permission across tenants', function (): void {
    $this->warden->tenant()->to(4);
    $this->warden->allow($this->user)->to(ElPandaPe\Warden\Tests\Fixtures\PermissionName::Publish);

    $why = $this->warden->explain($this->user, ElPandaPe\Warden\Tests\Fixtures\PermissionName::Publish);

    expect($why->cause)->toBe(Cause::GrantedDirectly)
        ->and($why->permission?->getAttribute('scope'))->toBe(4);
});

it('never blames a restricted role outside its context', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbidEveryone()->to('publish');
    $this->warden->forbid('auditor')->to('publish');
    $this->warden->assign('auditor')->on($org)->to($this->user);

    // The instance-less check never used the restricted auditor: the everyone
    // forbid is the true cause.
    expect($this->warden->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenToEveryone);

    // Inside its context the restricted role IS the cause, and gets named.
    $this->warden->allow($this->user)->to('edit', Account::class);
    $this->warden->forbid('auditor')->to('edit', Account::class);

    $why = $this->warden->explain($this->user, 'edit', $org);

    expect($why->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('auditor');
});

it('says the condition failed instead of reporting no matching grant', function (): void {
    $draft = Account::query()->create(['name' => 'Draft'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');

    $why = $this->warden->explain($this->user, 'view', $draft);

    expect($why->cause)->toBe(Cause::ConditionsNotMet)
        ->and($why->permission?->getAttribute('name'))->toBe('view')
        ->and((string) $why)->toBe('Denied by permission [view]: a rule matched but its conditions were not satisfied.');
});

it('does not claim a record was involved when the check named a class', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');

    $why = $this->warden->explain($this->user, 'view', Account::class);

    expect($why->cause)->toBe(Cause::ConditionsNotMet)
        ->and((string) $why)->not->toContain('this record');
});

it('still reports no matching grant when no row matched the shape at all', function (): void {
    $account = Account::query()->create(['name' => 'Any'])->refresh();

    $why = $this->warden->explain($this->user, 'view', $account);

    expect($why->cause)->toBe(Cause::NoMatchingGrant)
        ->and($why->permission)->toBeNull();
});

it('does not re-read the row the resolver already decided on', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    Illuminate\Support\Facades\DB::enableQueryLog();
    $this->warden->explain($this->user, 'edit-site');

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(4);
});

it('reads assigned_roles once when blaming a role, not once per consumer', function (): void {
    $this->warden->allow('editor')->to('edit-site');
    $this->warden->assign('editor')->to($this->user);

    Illuminate\Support\Facades\DB::flushQueryLog();
    Illuminate\Support\Facades\DB::enableQueryLog();

    $why = $this->warden->explain($this->user, 'edit-site');

    $log = Illuminate\Support\Facades\DB::getQueryLog();
    $assignments = array_filter(
        $log,
        fn (array $entry): bool => str_contains((string) $entry['query'], 'assigned_roles'),
    );

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($assignments)->toHaveCount(1)
        ->and($log)->toHaveCount(5);
});

it('blames the role rather than a direct grant that already ended', function (): void {
    $this->warden->allow($this->user)->until(Carbon::now()->subHour())->to('publish');
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->user);

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('editor');
});

it('blames the nested role that holds the grant', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('audit');
    nestRole('auditor', 'manager');
    $this->warden->assign('manager')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('auditor')
        ->and((string) $why)->toBe('Granted by permission [audit] via role [auditor].');
});

it('blames the nested role that holds the forbid', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbid('banned')->to('publish');
    nestRole('banned', 'suspended');
    $this->warden->assign('suspended')->to($this->user);

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('banned');
});

it('never blames a nested role reached through a restricted assignment outside its context', function (): void {
    config()->set('warden.roles.nested', true);
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbidEveryone()->to('publish');
    $this->warden->forbid('auditor')->to('publish');
    nestRole('auditor', 'reviewer');
    $this->warden->assign('reviewer')->on($org)->to($this->user);

    expect($this->warden->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenToEveryone);

    $this->warden->allow($this->user)->to('edit', Account::class);
    $this->warden->forbid('auditor')->to('edit', Account::class);

    $why = $this->warden->explain($this->user, 'edit', $org);

    expect($why->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('auditor');
});

it('blames a nested role from the closure the resolver already walked', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('audit');
    nestRole('auditor', 'manager');
    $this->warden->assign('manager')->to($this->user);

    Illuminate\Support\Facades\DB::flushQueryLog();
    Illuminate\Support\Facades\DB::enableQueryLog();

    $why = $this->warden->explain($this->user, 'audit');

    $log = Illuminate\Support\Facades\DB::getQueryLog();
    $assignments = array_filter(
        $log,
        fn (array $entry): bool => str_contains((string) $entry['query'], 'assigned_roles'),
    );

    expect($why->role?->getAttribute('name'))->toBe('auditor')
        ->and($assignments)->toHaveCount(3)
        ->and($log)->toHaveCount(7);
});

it('blames a role held directly before a nested one that also holds the grant', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('audit');
    $this->warden->allow('manager')->to('audit');
    nestRole('auditor', 'manager');
    $this->warden->assign('manager')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('manager');
});

it('passes over a trashed role to the next cause', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $this->warden->allow('editor')->to('publish');
    $this->warden->allowEveryone()->to('publish');
    $this->warden->assign('editor')->to($this->user);
    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::GrantedToEveryone)
        ->and($why->role)->toBeNull();
});

it('passes over a trashed nested role to the next cause', function (): void {
    config()->set('warden.roles.nested', true);
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $this->warden->allow('editor')->to('publish');
    $this->warden->allowEveryone()->to('publish');
    $this->warden->assign('editor')->to(SoftDeletingRole::query()->create(['name' => 'manager']));
    $this->warden->assign('manager')->to($this->user);
    SoftDeletingRole::query()->where('name', 'editor')->sole()->delete();

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::GrantedToEveryone)
        ->and($why->role)->toBeNull();
});
