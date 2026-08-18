<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

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
