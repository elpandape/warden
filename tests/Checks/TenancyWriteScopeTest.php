<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\BouncerServiceProvider;
use ElPandaPe\Bouncer\Database\AssignedRole;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Database\Tenancy\Tenancy;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('keeps global grants intact when revoking under a tenant', function (): void {
    $this->bouncer->allow($this->user)->to('publish');

    $this->bouncer->tenant()->to(1);
    $this->bouncer->disallow($this->user)->to('publish');

    // The global row survives: revokes only target the active scope.
    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeTrue();

    $this->bouncer->tenant()->remove();

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('does not lift a global forbid from inside a tenant', function (): void {
    $this->bouncer->allow($this->user)->to('publish');
    $this->bouncer->forbid($this->user)->to('publish');

    $this->bouncer->tenant()->to(1);
    $this->bouncer->unforbid($this->user)->to('publish');
    $this->bouncer->tenant()->remove();

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('creates a global forbid even when a tenant already has one', function (): void {
    $this->bouncer->allowEveryone()->to('delete');
    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->forbid($this->user)->to('delete');
    });

    // Without a tenant this must create a distinct global row, not match tenant 1's.
    $this->bouncer->forbid($this->user)->to('delete');

    $this->bouncer->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('delete'))->toBeFalse();
});

it('creates a global grant even when a tenant already has one', function (): void {
    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->allow($this->user)->to('edit');
    });

    $this->bouncer->allow($this->user)->to('edit');

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(2);

    $this->bouncer->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('edit'))->toBeTrue();
});

it('keeps global role assignments when retracting under a tenant', function (): void {
    $this->bouncer->assign('admin')->to($this->user);

    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->retract('admin')->from($this->user);
    });

    expect(AssignedRole::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($this->user->isAn('admin'))->toBeTrue();
});

it('keeps global role assignments when syncing under a tenant', function (): void {
    $this->bouncer->assign('admin')->to($this->user);

    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->sync($this->user)->roles(['editor']);
    });

    expect($this->user->isAn('admin'))->toBeTrue();

    $this->bouncer->tenant()->to(1);

    expect($this->user->isAn('admin'))->toBeTrue()
        ->and($this->user->isAn('editor'))->toBeTrue();
});

it('keeps global grants when syncing permissions under a tenant', function (): void {
    $this->bouncer->allow($this->user)->to('browse');

    $this->bouncer->tenant()->onceTo(1, function (): void {
        $this->bouncer->sync($this->user)->permissions(['edit']);
    });

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue();

    $this->bouncer->tenant()->to(1);

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit'))->toBeTrue();
});

it('syncs role grants globally with dontScopeRoleGrants', function (): void {
    $this->bouncer->tenant()->dontScopeRoleGrants()->to(1);

    $this->bouncer->sync('admin')->permissions(['audit']);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();

    // Syncing again replaces the same global rows instead of stacking scoped ones.
    $this->bouncer->sync('admin')->permissions(['report']);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();
});

it('filters the eager-loaded role fast path by tenant', function (): void {
    $this->bouncer->assign('guest')->to($this->user);

    $this->bouncer->tenant()->to(1);
    $this->bouncer->assign('admin')->to($this->user);

    $this->user->load('roles');

    expect($this->user->isAn('admin'))->toBeTrue()
        ->and($this->user->isA('guest'))->toBeTrue();

    // Same loaded relation, different tenant: the stale row must not leak.
    $this->bouncer->tenant()->to(2);

    expect($this->user->isAn('admin'))->toBeFalse()
        ->and($this->user->isA('guest'))->toBeTrue()
        ->and($this->user->isAll('guest'))->toBeTrue()
        ->and($this->user->isAll('guest', 'admin'))->toBeFalse();

    $this->bouncer->tenant()->remove();
    config()->set('bouncer.scope.null_behavior', 'strict');

    expect($this->user->isAn('admin'))->toBeFalse()
        ->and($this->user->isA('guest'))->toBeTrue();
});

it('hides tenant grants under strict behavior even with a global catalog', function (): void {
    $this->bouncer->tenant()->onlyRelations()->to(1);
    $this->bouncer->allow($this->user)->to('edit');
    $this->bouncer->tenant()->remove();

    config()->set('bouncer.scope.null_behavior', 'strict');

    // The permission stays global (onlyRelations); only the grant row is scoped.
    expect(Gate::forUser($this->user)->allows('edit'))->toBeFalse();
});

it('scopes whereIs queries to the active tenant', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->assign('admin')->to($this->user);

    expect(User::query()->whereIs('admin')->count())->toBe(1);

    $this->bouncer->tenant()->to(2);

    expect(User::query()->whereIs('admin')->count())->toBe(0);
});

it('resets tenant state between scoped container lifecycles', function (): void {
    app(Tenancy::class)->to(9);

    // Octane and queue workers flush scoped instances between operations.
    app()->forgetScopedInstances();

    expect(app(Tenancy::class)->current())->toBeNull();
});

it('registers a plain singleton when the reset opt-out is set', function (): void {
    config()->set('bouncer.octane.register_reset_listener', false);
    new BouncerServiceProvider(app())->register();

    // The container keeps the earlier scoped registration in this test app, so
    // cross-lifecycle persistence cannot be asserted here: exercise the branch
    // and confirm the rebound binding still shares state within the lifecycle.
    app(Tenancy::class)->to(9);

    expect(app(Tenancy::class)->current())->toBe(9);
});
