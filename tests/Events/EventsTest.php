<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Events\AssigningRole;
use ElPandaPe\Bouncer\Events\ForbiddingPermission;
use ElPandaPe\Bouncer\Events\GrantingPermission;
use ElPandaPe\Bouncer\Events\PermissionCreated;
use ElPandaPe\Bouncer\Events\PermissionDeleted;
use ElPandaPe\Bouncer\Events\PermissionForbidden;
use ElPandaPe\Bouncer\Events\PermissionGranted;
use ElPandaPe\Bouncer\Events\PermissionRevoked;
use ElPandaPe\Bouncer\Events\PermissionsSynced;
use ElPandaPe\Bouncer\Events\PermissionUnforbidden;
use ElPandaPe\Bouncer\Events\RoleAssigned;
use ElPandaPe\Bouncer\Events\RoleCreated;
use ElPandaPe\Bouncer\Events\RoleDeleted;
use ElPandaPe\Bouncer\Events\RoleRetracted;
use ElPandaPe\Bouncer\Events\RolesSynced;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

// Fake ONLY Bouncer's events: model hooks (titles, tenancy stamps) must stay live.
const BOUNCER_EVENTS = [
    PermissionGranted::class, PermissionRevoked::class,
    PermissionForbidden::class, PermissionUnforbidden::class,
    RoleAssigned::class, RoleRetracted::class,
    RolesSynced::class, PermissionsSynced::class,
    RoleCreated::class, RoleDeleted::class,
    PermissionCreated::class, PermissionDeleted::class,
];

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('announces grants with hydrated permissions', function (): void {
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->authority?->is($this->user) === true
        && $event->permissions->sole()->getAttribute('name') === 'edit-site'
        && $event->scope === null);
});

it('announces everyone-grants with a null authority', function (): void {
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->allowEveryone()->to('browse');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => ! $event->authority instanceof Illuminate\Database\Eloquent\Model);
});

it('announces forbids, revokes and unforbids symmetrically', function (): void {
    $this->bouncer->allow($this->user)->to('publish');

    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->forbid($this->user)->to('publish');
    $this->bouncer->unforbid($this->user)->to('publish');
    $this->bouncer->disallow($this->user)->to('publish');

    Event::assertDispatched(PermissionForbidden::class, fn (PermissionForbidden $event): bool => $event->permissions->sole()->getAttribute('name') === 'publish');
    Event::assertDispatched(PermissionUnforbidden::class);
    Event::assertDispatched(PermissionRevoked::class);
});

it('stays silent when a revoke removes nothing', function (): void {
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->disallow($this->user)->to('never-granted');

    Event::assertNotDispatched(PermissionRevoked::class);
});

it('announces role assignments and retractions', function (): void {
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->retract('admin')->from($this->user);
    $this->bouncer->retract('admin')->from($this->user);

    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->authority->is($this->user)
        && $event->roles->sole()->getAttribute('name') === 'admin'
        && ! $event->restrictedTo instanceof Illuminate\Database\Eloquent\Model);
    Event::assertDispatchedTimes(RoleRetracted::class, 1);
});

it('announces syncs with a full diff and no per-role noise', function (): void {
    $this->bouncer->assign(['admin', 'editor'])->to($this->user);

    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->sync($this->user)->roles(['editor', 'writer']);

    Event::assertDispatched(RolesSynced::class, function (RolesSynced $event): bool {
        $names = fn (iterable $models): array => collect($models)->map(
            fn (object $model): mixed => $model->getAttribute('name'),
        )->all();

        return $names($event->changes->attached) === ['writer']
            && $names($event->changes->detached) === ['admin']
            && $names($event->changes->kept) === ['editor'];
    });

    // The sync event tells the whole story: no per-role assignment events.
    Event::assertNotDispatched(RoleAssigned::class);
});

it('announces permission syncs with the forbidden flag', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->sync($this->user)->permissions(['publish']);
    $this->bouncer->sync($this->user)->forbiddenPermissions(['ban-users']);

    Event::assertDispatched(PermissionsSynced::class, function (PermissionsSynced $event): bool {
        if ($event->forbidden) {
            return collect($event->changes->attached)->sole()->getAttribute('name') === 'ban-users';
        }

        return collect($event->changes->attached)->sole()->getAttribute('name') === 'publish'
            && collect($event->changes->detached)->sole()->getAttribute('name') === 'edit-site';
    });
});

it('announces catalog lifecycle from the model layer', function (): void {
    Event::fake(BOUNCER_EVENTS);

    // On-the-fly creation counts: no dedicated create call needed.
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->assign('admin')->to($this->user);

    Role::query()->where('name', 'admin')->sole()->delete();
    Permission::query()->where('name', 'edit-site')->sole()->delete();

    Event::assertDispatched(PermissionCreated::class, fn (PermissionCreated $event): bool => $event->permission->getAttribute('name') === 'edit-site');
    Event::assertDispatched(RoleCreated::class, fn (RoleCreated $event): bool => $event->role->getAttribute('name') === 'admin');
    Event::assertDispatched(RoleDeleted::class);
    Event::assertDispatched(PermissionDeleted::class);
});

it('carries the active tenant in event payloads', function (): void {
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->tenant()->to(7);
    $this->bouncer->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->scope === 7);
});

it('goes quiet when events are disabled', function (): void {
    config()->set('bouncer.events_enabled', false);
    Event::fake(BOUNCER_EVENTS);

    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->sync($this->user)->roles([]);

    Event::assertNotDispatched(PermissionGranted::class);
    Event::assertNotDispatched(RoleAssigned::class);
    Event::assertNotDispatched(RolesSynced::class);
    Event::assertNotDispatched(RoleCreated::class);
    Event::assertNotDispatched(PermissionCreated::class);
});

it('lets cancellable listeners abort grants before any write', function (): void {
    config()->set('bouncer.cancellable_events', true);

    Event::listen(GrantingPermission::class, fn (GrantingPermission $event): bool => false);

    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->allow($this->user)->toOwnEverything();

    expect(Permission::query()->count())->toBe(0)
        ->and($this->user->can('edit-site'))->toBeFalse();
});

it('lets cancellable listeners abort forbids and assignments', function (): void {
    config()->set('bouncer.cancellable_events', true);
    $this->bouncer->allow($this->user)->to('publish');

    Event::listen(ForbiddingPermission::class, fn (ForbiddingPermission $event): bool => $event->permissions !== ['publish']);
    Event::listen(AssigningRole::class, fn (AssigningRole $event): bool => false);

    $this->bouncer->forbid($this->user)->to('publish');
    $this->bouncer->assign('admin')->to($this->user);

    expect($this->user->can('publish'))->toBeTrue()
        ->and($this->user->isAn('admin'))->toBeFalse();
});

it('ignores cancelling listeners unless the opt-in is set', function (): void {
    Event::listen(GrantingPermission::class, fn (): bool => false);

    $this->bouncer->allow($this->user)->to('edit-site');

    expect($this->user->can('edit-site'))->toBeTrue();
});

it('announces the true write scope in cancellable pre-events', function (): void {
    config()->set('bouncer.cancellable_events', true);
    $this->bouncer->tenant()->dontScopeRoleGrants()->to(42);

    $scopes = [];
    Event::listen(GrantingPermission::class, function (GrantingPermission $event) use (&$scopes): void {
        $scopes[] = $event->scope;
    });

    // A role authority writes its grants globally here; a user stays scoped.
    $this->bouncer->allow('admin')->to('audit');
    $this->bouncer->allow($this->user)->to('browse');

    expect($scopes)->toBe([null, 42]);
});

it('distinguishes ownership grants in cancellable pre-events', function (): void {
    config()->set('bouncer.cancellable_events', true);

    Event::listen(
        GrantingPermission::class,
        fn (GrantingPermission $event): ?bool => $event->onlyOwned ? false : null,
    );

    $this->bouncer->allow($this->user)->toOwnEverything();
    $this->bouncer->allow($this->user)->to('browse');

    expect($this->user->can('browse'))->toBeTrue()
        ->and(Permission::query()->where('only_owned', true)->count())->toBe(0);
});

it('never cancels the writes a sync delegates', function (): void {
    config()->set('bouncer.cancellable_events', true);

    Event::listen(AssigningRole::class, fn (): bool => false);

    $this->bouncer->sync($this->user)->roles(['editor']);

    expect($this->user->isAn('editor'))->toBeTrue();
});
