<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Checks\Explain\Cause;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('explains direct grants', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    $why = $this->bouncer->explain($this->user, 'edit-site');

    expect($why->allowed())->toBeTrue()
        ->and($why->cause)->toBe(Cause::GrantedDirectly)
        ->and($why->permission?->getAttribute('name'))->toBe('edit-site')
        ->and((string) $why)->toBe('Granted by permission [edit-site], held directly.');
});

it('explains grants that arrive through a role', function (): void {
    $this->bouncer->allow('admin')->to('audit');
    $this->bouncer->assign('admin')->to($this->user);

    $why = $this->bouncer->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('admin')
        ->and((string) $why)->toBe('Granted by permission [audit] via role [admin].');
});

it('explains everyone-grants and explicit forbids', function (): void {
    $account = Account::query()->create(['name' => 'A'])->refresh();

    $this->bouncer->allowEveryone()->to('browse');
    $this->bouncer->allow($this->user)->to('edit', $account);
    $this->bouncer->forbid($this->user)->to('edit', $account);

    expect($this->bouncer->explain($this->user, 'browse')->cause)->toBe(Cause::GrantedToEveryone)
        ->and($this->bouncer->explain($this->user, 'edit', $account)->cause)->toBe(Cause::ForbiddenDirectly)
        ->and((string) $this->bouncer->explain($this->user, 'edit', $account))
        ->toContain('Explicitly forbidden');
});

it('explains forbids through roles and to everyone', function (): void {
    $this->bouncer->allow($this->user)->to('publish');
    $this->bouncer->forbid('banned')->to('publish');
    $this->bouncer->assign('banned')->to($this->user);

    expect($this->bouncer->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($this->bouncer->explain($this->user, 'publish')->role?->getAttribute('name'))->toBe('banned');

    $this->bouncer->retract('banned')->from($this->user);
    $this->bouncer->forbidEveryone()->to('publish');

    expect($this->bouncer->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenToEveryone);
});

it('explains abstentions', function (): void {
    $why = $this->bouncer->explain($this->user, 'never-granted');

    expect($why->allowed())->toBeFalse()
        ->and($why->cause)->toBe(Cause::NoMatchingGrant)
        ->and($why->permission)->toBeNull()
        ->and((string) $why)->toContain('app policies decide')
        ->and($this->bouncer->explain($this->user, 'edit', 'not-a-class')->cause)->toBe(Cause::NotApplicable);
});

it('diagnoses from live rows even when the cache is stale', function (): void {
    config()->set('bouncer.cache.enabled', true);
    $this->bouncer->allow($this->user)->to('edit-site');

    expect($this->user->can('edit-site'))->toBeTrue();

    ElPandaPe\Bouncer\Models\Grant::query()->withoutGlobalScopes()->delete();

    // The cached check still says yes; the explanation tells the truth.
    expect($this->user->can('edit-site'))->toBeTrue()
        ->and($this->bouncer->explain($this->user, 'edit-site')->cause)->toBe(Cause::NoMatchingGrant);
});

it('accepts enums and hydrates the decisive permission across tenants', function (): void {
    $this->bouncer->tenant()->to(4);
    $this->bouncer->allow($this->user)->to(ElPandaPe\Bouncer\Tests\Fixtures\PermissionName::Publish);

    $why = $this->bouncer->explain($this->user, ElPandaPe\Bouncer\Tests\Fixtures\PermissionName::Publish);

    expect($why->cause)->toBe(Cause::GrantedDirectly)
        ->and($why->permission?->getAttribute('scope'))->toBe(4);
});

it('never blames a restricted role outside its context', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->bouncer->allow($this->user)->to('publish');
    $this->bouncer->forbidEveryone()->to('publish');
    $this->bouncer->forbid('auditor')->to('publish');
    $this->bouncer->assign('auditor')->on($org)->to($this->user);

    // The instance-less check never used the restricted auditor: the everyone
    // forbid is the true cause.
    expect($this->bouncer->explain($this->user, 'publish')->cause)->toBe(Cause::ForbiddenToEveryone);

    // Inside its context the restricted role IS the cause, and gets named.
    $this->bouncer->allow($this->user)->to('edit', Account::class);
    $this->bouncer->forbid('auditor')->to('edit', Account::class);

    $why = $this->bouncer->explain($this->user, 'edit', $org);

    expect($why->cause)->toBe(Cause::ForbiddenViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('auditor');
});
