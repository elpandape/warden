<?php

declare(strict_types=1);

use ElPandaPe\Warden\Events\AssigningRole;
use ElPandaPe\Warden\Events\ForbiddingPermission;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionForbidden;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionsSynced;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\RetractingRole;
use ElPandaPe\Warden\Events\RevokingPermission;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Events\RolesSynced;
use ElPandaPe\Warden\Events\UnforbiddingPermission;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

// Fake ONLY Warden's events: model hooks (titles, tenancy stamps) must stay live.
const WARDEN_EVENTS = [
    PermissionGranted::class, PermissionRevoked::class,
    PermissionForbidden::class, PermissionUnforbidden::class,
    RoleAssigned::class, RoleRetracted::class,
    RolesSynced::class, PermissionsSynced::class,
    RoleCreated::class, RoleDeleted::class,
    PermissionCreated::class, PermissionDeleted::class,
];

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('announces grants with hydrated permissions', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->authority?->is($this->user) === true
        && $event->permissions->sole()->getAttribute('name') === 'edit-site'
        && $event->scope === null);
});

it('announces everyone-grants with a null authority', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allowEveryone()->to('browse');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => ! $event->authority instanceof Illuminate\Database\Eloquent\Model);
});

it('announces forbids, revokes and unforbids symmetrically', function (): void {
    $this->warden->allow($this->user)->to('publish');

    Event::fake(WARDEN_EVENTS);

    $this->warden->forbid($this->user)->to('publish');
    $this->warden->unforbid($this->user)->to('publish');
    $this->warden->disallow($this->user)->to('publish');

    Event::assertDispatched(PermissionForbidden::class, fn (PermissionForbidden $event): bool => $event->permissions->sole()->getAttribute('name') === 'publish');
    Event::assertDispatched(PermissionUnforbidden::class);
    Event::assertDispatched(PermissionRevoked::class);
});

it('stays silent when a revoke removes nothing', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->disallow($this->user)->to('never-granted');

    Event::assertNotDispatched(PermissionRevoked::class);
});

it('announces role assignments and retractions', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->assign('admin')->to($this->user);
    $this->warden->retract('admin')->from($this->user);
    $this->warden->retract('admin')->from($this->user);

    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->authority->is($this->user)
        && $event->roles->sole()->getAttribute('name') === 'admin'
        && ! $event->restrictedTo instanceof Illuminate\Database\Eloquent\Model);
    Event::assertDispatchedTimes(RoleRetracted::class, 1);
});

it('announces syncs with a full diff and no per-role noise', function (): void {
    $this->warden->assign(['admin', 'editor'])->to($this->user);

    Event::fake(WARDEN_EVENTS);

    $this->warden->sync($this->user)->roles(['editor', 'writer']);

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
    $this->warden->allow($this->user)->to('edit-site');

    Event::fake(WARDEN_EVENTS);

    $this->warden->sync($this->user)->permissions(['publish']);
    $this->warden->sync($this->user)->forbiddenPermissions(['ban-users']);

    Event::assertDispatched(PermissionsSynced::class, function (PermissionsSynced $event): bool {
        if ($event->forbidden) {
            return collect($event->changes->attached)->sole()->getAttribute('name') === 'ban-users';
        }

        return collect($event->changes->attached)->sole()->getAttribute('name') === 'publish'
            && collect($event->changes->detached)->sole()->getAttribute('name') === 'edit-site';
    });
});

it('announces catalog lifecycle from the model layer', function (): void {
    Event::fake(WARDEN_EVENTS);

    // On-the-fly creation counts: no dedicated create call needed.
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->assign('admin')->to($this->user);

    Role::query()->where('name', 'admin')->sole()->delete();
    Permission::query()->where('name', 'edit-site')->sole()->delete();

    Event::assertDispatched(PermissionCreated::class, fn (PermissionCreated $event): bool => $event->permission->getAttribute('name') === 'edit-site');
    Event::assertDispatched(RoleCreated::class, fn (RoleCreated $event): bool => $event->role->getAttribute('name') === 'admin');
    Event::assertDispatched(RoleDeleted::class);
    Event::assertDispatched(PermissionDeleted::class);
});

it('carries the active tenant in event payloads', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->tenant()->to(7);
    $this->warden->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->scope === 7);
});

it('goes quiet when events are disabled', function (): void {
    config()->set('warden.events_enabled', false);
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->assign('admin')->to($this->user);
    $this->warden->sync($this->user)->roles([]);

    Event::assertNotDispatched(PermissionGranted::class);
    Event::assertNotDispatched(RoleAssigned::class);
    Event::assertNotDispatched(RolesSynced::class);
    Event::assertNotDispatched(RoleCreated::class);
    Event::assertNotDispatched(PermissionCreated::class);
});

it('lets cancellable listeners abort grants before any write', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(GrantingPermission::class, fn (GrantingPermission $event): bool => false);

    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->allow($this->user)->toOwnEverything();

    expect(Permission::query()->count())->toBe(0)
        ->and($this->user->can('edit-site'))->toBeFalse();
});

it('lets cancellable listeners abort forbids and assignments', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->allow($this->user)->to('publish');

    Event::listen(ForbiddingPermission::class, fn (ForbiddingPermission $event): bool => $event->permissions !== ['publish']);
    Event::listen(AssigningRole::class, fn (AssigningRole $event): bool => false);

    $this->warden->forbid($this->user)->to('publish');
    $this->warden->assign('admin')->to($this->user);

    expect($this->user->can('publish'))->toBeTrue()
        ->and($this->user->isAn('admin'))->toBeFalse();
});

it('ignores cancelling listeners unless the opt-in is set', function (): void {
    Event::listen(GrantingPermission::class, fn (): bool => false);

    $this->warden->allow($this->user)->to('edit-site');

    expect($this->user->can('edit-site'))->toBeTrue();
});

it('announces the true write scope in cancellable pre-events', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->tenant()->dontScopeRoleGrants()->to(42);

    $scopes = [];
    Event::listen(GrantingPermission::class, function (GrantingPermission $event) use (&$scopes): void {
        $scopes[] = $event->scope;
    });

    // A role authority writes its grants globally here; a user stays scoped.
    $this->warden->allow('admin')->to('audit');
    $this->warden->allow($this->user)->to('browse');

    expect($scopes)->toBe([null, 42]);
});

it('distinguishes ownership grants in cancellable pre-events', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(
        GrantingPermission::class,
        fn (GrantingPermission $event): ?bool => $event->onlyOwned ? false : null,
    );

    $this->warden->allow($this->user)->toOwnEverything();
    $this->warden->allow($this->user)->to('browse');

    expect($this->user->can('browse'))->toBeTrue()
        ->and(Permission::query()->where('only_owned', true)->count())->toBe(0);
});

it('never cancels the writes a sync delegates', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(AssigningRole::class, fn (): bool => false);

    $this->warden->sync($this->user)->roles(['editor']);

    expect($this->user->isAn('editor'))->toBeTrue();
});

it('stays silent when assigning a role the authority already holds', function (): void {
    $this->warden->assign('editor')->to($this->user);

    Event::fake(WARDEN_EVENTS);

    $this->warden->assign('editor')->to($this->user);

    Event::assertNotDispatched(RoleAssigned::class);
});

it('stays silent when granting a permission the authority already has', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('edit-site');

    Event::assertNotDispatched(PermissionGranted::class);
});

it('lets cancellable listeners abort a revoke before anything is deleted', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->allow($this->user)->to('publish');

    Event::listen(RevokingPermission::class, fn (RevokingPermission $event): bool => false);

    $this->warden->disallow($this->user)->to('publish');

    expect($this->user->can('publish'))->toBeTrue();
});

it('lets cancellable listeners abort an unforbid before anything is deleted', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbid($this->user)->to('publish');

    Event::listen(UnforbiddingPermission::class, fn (UnforbiddingPermission $event): bool => false);

    $this->warden->unforbid($this->user)->to('publish');

    expect($this->user->can('publish'))->toBeFalse();
});

it('lets cancellable listeners abort a retract before anything is deleted', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->assign('editor')->to($this->user);

    Event::listen(RetractingRole::class, fn (RetractingRole $event): bool => false);

    $this->warden->retract('editor')->from($this->user);

    expect($this->user->isAn('editor'))->toBeTrue();
});

it('announces the re-point when a chain narrows a grant', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');

    Event::assertDispatched(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionGranted::class, 2);
});

it('announces the re-point on the forbid polarity too', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->forbid($this->user)->to('view', Account::class)->where('name', '=', 'Published');

    Event::assertDispatched(PermissionUnforbidden::class, 1);
    Event::assertDispatched(PermissionForbidden::class, 2);
});

it('carries the acting user in write events', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->assign('editor')->to($this->user);

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->actor?->is($admin) === true);
    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->actor?->is($admin) === true);
});

it('leaves the actor null when nobody is acting', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => ! $event->actor instanceof Illuminate\Database\Eloquent\Model);
});

it('names the actor through a configured resolver', function (): void {
    $auditor = User::query()->create(['name' => 'Auditor']);
    config()->set('warden.actor_resolver', ElPandaPe\Warden\Tests\Fixtures\FixedActorResolver::class);

    Event::fake(WARDEN_EVENTS);

    app(Warden::class)->allow($this->user)->to('edit-site');

    Event::assertDispatched(PermissionGranted::class, fn (PermissionGranted $event): bool => $event->actor?->is($auditor) === true);
});

it('announces the grants a permission delete took with it', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    Event::fake(WARDEN_EVENTS);

    Permission::query()->where('name', 'edit-site')->sole()->delete();

    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true
        && $event->permissions->sole()->getAttribute('name') === 'edit-site');
});

it('announces a cascaded forbid as unforbidden, and an everyone-grant with no authority', function (): void {
    $this->warden->forbid($this->user)->to('publish');
    $this->warden->allowEveryone()->to('publish');

    Event::fake(WARDEN_EVENTS);

    Permission::query()->where('name', 'publish')->sole()->delete();

    Event::assertDispatched(PermissionUnforbidden::class, fn (PermissionUnforbidden $event): bool => $event->authority?->is($this->user) === true);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => ! $event->authority instanceof Illuminate\Database\Eloquent\Model);
});

it('announces a permission delete before the grants it took with it', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    $heard = [];
    Event::listen([PermissionDeleted::class, PermissionRevoked::class], function (object $event) use (&$heard): void {
        $heard[] = $event::class;
    });

    Permission::query()->where('name', 'edit-site')->sole()->delete();

    expect($heard)->toBe([PermissionDeleted::class, PermissionRevoked::class]);
});

it('announces no phantom revoke when a later delete reuses the object id of a vetoed one', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    Event::listen('eloquent.deleting: *', fn (string $event, array $payload): ?bool => $payload[0] instanceof Permission ? false : null);

    $permission = Permission::query()->where('name', 'edit-site')->sole();
    $vetoed = $permission->delete();
    $staleId = spl_object_id($permission);
    unset($permission);

    $role = new Role(['name' => 'bystander']);

    expect($vetoed)->toBeFalse()
        ->and(spl_object_id($role))->toBe($staleId);

    $role->save();

    Event::fake(WARDEN_EVENTS);

    $role->delete();

    Event::assertDispatched(RoleDeleted::class);
    Event::assertNotDispatched(PermissionRevoked::class);
});
