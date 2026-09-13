<?php

declare(strict_types=1);

use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Events\AssigningRole;
use ElPandaPe\Warden\Events\AssignmentChange;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\ForbiddingPermission;
use ElPandaPe\Warden\Events\GrantChange;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionForbidden;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionsSynced;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\PermissionUpdated;
use ElPandaPe\Warden\Events\RetractingRole;
use ElPandaPe\Warden\Events\RevokingPermission;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Events\RolesSynced;
use ElPandaPe\Warden\Events\RoleUpdated;
use ElPandaPe\Warden\Events\UnforbiddingPermission;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\CountingActorResolver;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

// Fake ONLY Warden's events: model hooks (titles, tenancy stamps) must stay live.
const WARDEN_EVENTS = [
    PermissionGranted::class, PermissionRevoked::class,
    PermissionForbidden::class, PermissionUnforbidden::class,
    RoleAssigned::class, RoleRetracted::class,
    RolesSynced::class, PermissionsSynced::class,
    RoleCreated::class, RoleDeleted::class,
    PermissionCreated::class, PermissionDeleted::class,
    RoleUpdated::class, PermissionUpdated::class,
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

it('names in detached only the grants a permission sync removed', function (): void {
    $acme = Account::query()->create(['name' => 'Acme']);

    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', $acme);
    $this->warden->allow($this->user)->toOwn(Account::class, 'update');
    $this->warden->allow($this->user)->to('audit', Account::class)->where('name', '=', 'Acme');

    Event::fake(WARDEN_EVENTS);

    $this->warden->sync($this->user)->permissions(['publish']);

    Event::assertDispatched(PermissionsSynced::class, fn (PermissionsSynced $event): bool => $event->changes->attached->pluck('name')->all() === ['publish']
        && $event->changes->detached->pluck('name')->all() === ['edit-site']
        && $event->changes->kept->isEmpty());

    expect($this->user->can('view', $acme))->toBeTrue();
});

it('keeps an entity-scoped permission a sync names as a model', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);
    $view = Permission::query()->where('name', 'view')->sole();

    Event::fake(WARDEN_EVENTS);

    $this->warden->sync($this->user)->permissions([$view]);

    Event::assertDispatched(PermissionsSynced::class, fn (PermissionsSynced $event): bool => $event->changes->attached->isEmpty()
        && $event->changes->detached->isEmpty()
        && $event->changes->kept->sole()->is($view));
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

it('announces an identical constrained chain as its ephemeral base alone', function (): void {
    withForeignKeys();
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $twinGrant = Grant::query()->sole()->getKey();

    $seen = [];
    Event::listen(WARDEN_EVENTS, function (object $event) use (&$seen): void {
        $seen[] = $event;
    });

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    expect(array_map(fn (object $event): string => $event::class, $seen))->toBe([
        PermissionCreated::class,
        PermissionGranted::class,
        PermissionRevoked::class,
        PermissionDeleted::class,
    ]);

    [$created, $granted, $revoked, $deleted] = $seen;

    expect($created->permission->getAttribute('options'))->toBeNull()
        ->and($granted->permissions->sole()->is($created->permission))->toBeTrue()
        ->and($revoked->permissions->sole()->is($created->permission))->toBeTrue()
        ->and($deleted->permission->is($created->permission))->toBeTrue()
        ->and(Grant::query()->sole()->getKey())->toBe($twinGrant);
});

it('announces the twin a changed condition replaced', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $acme = Permission::query()->whereNotNull('options')->sole();

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Globex');

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->permissions->count() === 2
        && $event->permissions->first()?->is($acme) === true
        && $event->permissions->last()?->getAttribute('options') === null
        && collect($event->grants)->pluck('permission')->all() === $event->permissions->all());
});

it('announces the prohibition a changed condition lifted', function (): void {
    $this->warden->forbid($this->user)->to('view', Account::class)->where('name', 'Acme');
    $acme = Permission::query()->whereNotNull('options')->sole();

    Event::fake(WARDEN_EVENTS);

    $this->warden->forbid($this->user)->to('view', Account::class)->where('name', 'Globex');

    Event::assertDispatchedTimes(PermissionUnforbidden::class, 1);
    Event::assertDispatched(PermissionUnforbidden::class, fn (PermissionUnforbidden $event): bool => $event->permissions->first()?->is($acme) === true
        && $event->grants[0]->permission->is($acme));
    Event::assertNotDispatched(PermissionRevoked::class);
});

it('stays silent when where() restates the condition of the twin it was given', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $twin = Permission::query()->whereNotNull('options')->sole();
    $grant = Grant::query()->sole()->getKey();
    $version = Cache::store('array')->get('warden:v:a');

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to($twin)->where('name', 'Acme');

    Event::assertNothingDispatched();

    expect(Grant::query()->sole()->getKey())->toBe($grant)
        ->and(Cache::store('array')->get('warden:v:a'))->toBe($version);
});

it('keeps a plain grant beside the twin when where() restates its condition', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $this->warden->allow($this->user)->to('view', Account::class);
    $twin = Permission::query()->whereNotNull('options')->sole();
    $grants = Grant::query()->orderBy('id')->pluck('id')->all();

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to($twin)->where('name', 'Acme');

    Event::assertNothingDispatched();

    expect($grants)->toHaveCount(2)
        ->and(Grant::query()->orderBy('id')->pluck('id')->all())->toBe($grants);
});

it('leaves the twin a throwing creation listener was told about', function (): void {
    $globex = Account::query()->create(['name' => 'Globex'])->refresh();
    $this->warden->allow($this->user)->to('view', Account::class);
    $outside = DB::transactionLevel();

    $levels = [];
    Event::listen(PermissionCreated::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();

        throw new RuntimeException('interrupted');
    });

    expect(fn (): mixed => $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme'))
        ->toThrow(RuntimeException::class, 'interrupted')
        ->and($levels)->toBe([$outside])
        ->and(Permission::query()->whereNotNull('options')->count())->toBe(1)
        ->and(Gate::forUser($this->user)->allows('view', $globex))->toBeTrue();
});

it('finishes the re-point before a listener of the orphaned base can throw', function (): void {
    withForeignKeys();
    $acme = Account::query()->create(['name' => 'Acme'])->refresh();
    $globex = Account::query()->create(['name' => 'Globex'])->refresh();

    Event::listen(PermissionDeleted::class, function (): void {
        throw new RuntimeException('interrupted');
    });

    expect(fn (): mixed => $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme'))
        ->toThrow(RuntimeException::class, 'interrupted')
        ->and(Gate::forUser($this->user)->allows('view', $acme))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $globex))->toBeFalse()
        ->and(Permission::query()->whereNull('options')->exists())->toBeFalse();
});

it('announces every grant of a narrowing chain at the caller\'s transaction level', function (): void {
    $levels = [];
    $outside = DB::transactionLevel();

    Event::listen(PermissionGranted::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    DB::transaction(function (): void {
        $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    });

    expect($levels)->toHaveCount(2)
        ->and(array_unique($levels))->toBe([$outside + 1]);
});

it('keeps the catalog writes of a narrowing chain outside its own transaction', function (): void {
    $levels = [];
    $outside = DB::transactionLevel();

    DB::transaction(function () use (&$levels): void {
        $chain = $this->warden->allow($this->user)->to('view', Account::class);

        Event::listen([PermissionCreated::class, PermissionDeleted::class], function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $chain->where('name', 'Acme');
    });

    expect($levels)->toBe([$outside + 1, $outside + 1]);
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

it('names a holder from another tenant in a cascaded revoke instead of everyone', function (): void {
    $this->warden->tenant()->to(7);
    $this->warden->allow('editor')->to('publish');

    $editor = Role::query()->where('name', 'editor')->sole();

    $this->warden->tenant()->to(5);

    Event::fake(WARDEN_EVENTS);

    Permission::query()->withoutGlobalScopes()->where('name', 'publish')->sole()->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($editor) === true
        && $event->scope === 7);
});

it('skips a cascaded holder whose row is gone instead of announcing everyone', function (): void {
    $gone = User::query()->create(['name' => 'Gone']);
    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow($gone)->to('publish');

    User::query()->whereKey($gone->getKey())->delete();

    Event::fake(WARDEN_EVENTS);

    Permission::query()->where('name', 'publish')->sole()->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true);
});

it('warns about a cascaded holder type no class maps and announces nothing for it', function (): void {
    $this->warden->allow($this->user)->to('publish');
    $permission = Permission::query()->where('name', 'publish')->sole();

    DB::table('grants')->insert([
        'permission_id' => $permission->getKey(),
        'entity_type' => 'nothing.maps.here',
        'entity_id' => 1,
        'forbidden' => false,
    ]);

    Log::spy();
    Event::fake(WARDEN_EVENTS);

    $permission->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, '[nothing.maps.here]'),
    );
});

it('announces nothing for a cascaded row that names a holder type but no key', function (): void {
    $this->warden->allow($this->user)->to('publish');
    $permission = Permission::query()->where('name', 'publish')->sole();

    DB::table('grants')->insert([
        'permission_id' => $permission->getKey(),
        'entity_type' => $this->user->getMorphClass(),
        'entity_id' => null,
        'forbidden' => false,
    ]);

    Event::fake(WARDEN_EVENTS);

    $permission->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true);
});

it('announces only the permissions a grant wrote', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);

    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to(['view', 'update'], Account::class);

    $event = Event::dispatched(PermissionGranted::class)->sole()[0];

    expect($event->permissions->pluck('name')->all())->toBe(['update'])
        ->and($event->grants)->toHaveCount(1)
        ->and($event->grants[0]->permission->is($event->permissions->sole()))->toBeTrue()
        ->and($event->grants[0]->created)->toBeTrue()
        ->and($event->grants[0]->expiresAt)->toBeNull()
        ->and($event->grants[0]->previousExpiresAt)->toBeNull();
});

it('announces only the forbids a forbid wrote', function (): void {
    $this->warden->forbid($this->user)->to('delete', Account::class);

    Event::fake(WARDEN_EVENTS);

    $this->warden->forbid($this->user)->to(['delete', 'archive'], Account::class);

    $event = Event::dispatched(PermissionForbidden::class)->sole()[0];

    expect($event->permissions->pluck('name')->all())->toBe(['archive'])
        ->and($event->grants)->toHaveCount(1)
        ->and($event->grants[0]->permission->is($event->permissions->sole()))->toBeTrue()
        ->and($event->grants[0]->created)->toBeTrue()
        ->and($event->grants[0]->expiresAt)->toBeNull()
        ->and($event->grants[0]->previousExpiresAt)->toBeNull();
});

it('keeps a grant event in step with its entries, in the order asked', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->to(['publish', 'archive', 'publish']);

    $event = Event::dispatched(PermissionGranted::class)->sole()[0];

    expect($event->permissions->pluck('name')->all())->toBe(['publish', 'archive'])
        ->and(array_map(fn (GrantChange $change): mixed => $change->permission->getAttribute('name'), $event->grants))
        ->toBe(['publish', 'archive']);
});

it('restores the grant entries of a queued grant event after their rows are gone', function (): void {
    Event::fake(WARDEN_EVENTS);

    $this->warden->allow($this->user)->until(Carbon::parse('2026-12-31 23:59:59'))->to('edit-site');

    $payload = serialize(Event::dispatched(PermissionGranted::class)->sole()[0]);

    Grant::query()->delete();
    Permission::query()->withoutGlobalScopes()->delete();

    $restored = unserialize($payload);

    expect(Permission::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and($restored->authority->is($this->user))->toBeTrue()
        ->and($restored->grants[0]->permission->getAttribute('name'))->toBe('edit-site')
        ->and($restored->grants[0]->expiresAt?->toDateTimeString())->toBe('2026-12-31 23:59:59');
});

it('announces an assignment only to the authorities that gained the role', function (): void {
    $other = User::query()->create(['name' => 'Luis']);
    $this->warden->assign('editor')->to($this->user);

    Event::fake(WARDEN_EVENTS);

    $this->warden->assign('editor')->to([$this->user, $other]);

    Event::assertDispatchedTimes(RoleAssigned::class, 1);
    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->authority->is($other)
        && $event->roles->sole()->getAttribute('name') === 'editor');
});

it('announces only the roles an authority gained', function (): void {
    $this->warden->assign('editor')->to($this->user);

    Event::fake(WARDEN_EVENTS);

    $this->warden->assign(['editor', 'auditor'])->to($this->user);

    $event = Event::dispatched(RoleAssigned::class)->sole()[0];

    expect($event->roles->pluck('name')->all())->toBe(['auditor'])
        ->and($event->assignments)->toHaveCount(1)
        ->and($event->assignments[0]->role->is($event->roles->sole()))->toBeTrue()
        ->and($event->assignments[0]->created)->toBeTrue()
        ->and($event->assignments[0]->expiresAt)->toBeNull()
        ->and($event->assignments[0]->previousExpiresAt)->toBeNull();
});

it('keeps each assignment event to its own authority, in the order asked', function (): void {
    $other = User::query()->create(['name' => 'Luis']);
    $this->warden->assign('editor')->to($other);

    Event::fake(WARDEN_EVENTS);

    $this->warden->assign(['editor', 'auditor'])->to([$other, $this->user]);

    $events = Event::dispatched(RoleAssigned::class)->map(fn (array $arguments): RoleAssigned => $arguments[0])->values();

    expect($events)->toHaveCount(2)
        ->and($events[0]->authority->is($other))->toBeTrue()
        ->and($events[0]->roles->pluck('name')->all())->toBe(['auditor'])
        ->and($events[1]->authority->is($this->user))->toBeTrue()
        ->and($events[1]->roles->pluck('name')->all())->toBe(['editor', 'auditor'])
        ->and(array_map(fn (AssignmentChange $change): mixed => $change->role->getAttribute('name'), $events[1]->assignments))
        ->toBe(['editor', 'auditor']);
});

it('resolves the actor once for an assignment to many authorities', function (): void {
    Role::query()->create(['name' => 'editor']);
    Role::query()->create(['name' => 'auditor']);
    $other = User::query()->create(['name' => 'Luis']);
    $resolver = new CountingActorResolver;
    app()->instance(ActorResolver::class, $resolver);

    $this->warden->assign(['editor', 'auditor'])->to([$this->user, $other]);

    expect($resolver->calls)->toBe(1);
});

it('announces only the permissions a revoke removed', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow($other)->to('archive');

    Event::fake(WARDEN_EVENTS);

    $this->warden->disallow($this->user)->to(['archive', 'publish']);

    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->permissions->pluck('name')->all() === ['publish']
        && array_map(fn (GrantRemoval $grant): mixed => $grant->permission->getAttribute('name'), $event->grants) === ['publish']);
});

it('announces only the forbids an unforbid lifted', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $this->warden->forbid($this->user)->to('publish');
    $this->warden->forbid($other)->to('archive');

    Event::fake(WARDEN_EVENTS);

    $this->warden->unforbid($this->user)->to(['archive', 'publish']);

    Event::assertDispatched(PermissionUnforbidden::class, fn (PermissionUnforbidden $event): bool => $event->permissions->pluck('name')->all() === ['publish']
        && array_map(fn (GrantRemoval $grant): mixed => $grant->permission->getAttribute('name'), $event->grants) === ['publish']);
});

it('announces only the roles an authority lost', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $ana = User::query()->create(['name' => 'Ana']);
    $this->warden->assign('editor')->to($this->user);
    $this->warden->assign('auditor')->to($ana);
    $this->actingAs($admin);

    Event::fake(WARDEN_EVENTS);

    $this->warden->retract(['editor', 'auditor'])->from([$this->user, $ana]);

    Event::assertDispatchedTimes(RoleRetracted::class, 2);
    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->authority->is($this->user)
        && $event->roles->pluck('name')->all() === ['editor']
        && array_map(fn (AssignmentRemoval $assignment): mixed => $assignment->role->getAttribute('name'), $event->assignments) === ['editor']
        && $event->actor?->is($admin) === true);
    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->authority->is($ana)
        && $event->roles->pluck('name')->all() === ['auditor']
        && $event->actor?->is($admin) === true);
});

it('names what a removal took in the order the call asked for it', function (): void {
    $this->warden->allow($this->user)->to(['edit', 'archive', 'publish']);
    $this->warden->assign(['auditor', 'editor', 'viewer'])->to($this->user);
    $archive = Permission::query()->where('name', 'archive')->sole();
    $editor = Role::query()->where('name', 'editor')->sole();

    Event::fake(WARDEN_EVENTS);

    $this->warden->disallow($this->user)->to(['publish', $archive, 'edit']);
    $this->warden->retract(['viewer', $editor, 'auditor'])->from($this->user);

    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->permissions->pluck('name')->all() === ['publish', 'archive', 'edit']
        && array_map(fn (GrantRemoval $grant): mixed => $grant->permission->getAttribute('name'), $event->grants) === ['publish', 'archive', 'edit']);
    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->roles->pluck('name')->all() === ['viewer', 'editor', 'auditor']
        && array_map(fn (AssignmentRemoval $assignment): mixed => $assignment->role->getAttribute('name'), $event->assignments) === ['viewer', 'editor', 'auditor']);
});

it('retracts from every authority before a listener that throws can stop it', function (): void {
    $ana = User::query()->create(['name' => 'Ana']);
    $this->warden->assign('editor')->to([$this->user, $ana]);

    Event::listen(RoleRetracted::class, function (RoleRetracted $event): void {
        if ($event->authority->is($this->user)) {
            throw new RuntimeException('The audit log is down.');
        }
    });

    $retract = $this->warden->retract('editor');

    expect(fn (): mixed => $retract->from([$this->user, $ana]))->toThrow(RuntimeException::class, 'The audit log is down.')
        ->and($retract->retractedCount())->toBe(2)
        ->and(AssignedRole::query()->count())->toBe(0);
});

it('lets a revoke listener read the revoked state through the cache', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow($this->user)->to('publish');

    expect($this->user->can('publish'))->toBeTrue();

    $seen = null;

    Event::listen(PermissionRevoked::class, function () use (&$seen): void {
        $seen = $this->user->can('publish');
    });

    $this->warden->disallow($this->user)->to('publish');

    expect($seen)->toBeFalse();
});

it('deletes and announces nothing when a cancellable listener vetoes a removal', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbid($this->user)->to('archive');
    $this->warden->assign('editor')->to($this->user);

    Event::listen(RevokingPermission::class, fn (): bool => false);
    Event::listen(UnforbiddingPermission::class, fn (): bool => false);
    Event::listen(RetractingRole::class, fn (): bool => false);
    Event::fake([PermissionRevoked::class, PermissionUnforbidden::class, RoleRetracted::class]);

    $this->warden->disallow($this->user)->to('publish');
    $this->warden->unforbid($this->user)->to('archive');
    $retract = $this->warden->retract('editor')->from($this->user);

    Event::assertNothingDispatched();
    expect(Grant::query()->count())->toBe(2)
        ->and(AssignedRole::query()->count())->toBe(1)
        ->and($retract->retractedCount())->toBe(0);
});

it('announces only the grants its own deletes removed when another write takes one first', function (): void {
    $this->warden->allow($this->user)->to(['publish', 'archive']);
    $archive = Permission::query()->where('name', 'archive')->sole()->getKey();
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$raced, $archive): void {
        if (! $raced && str_starts_with(strtolower($query), 'delete')) {
            $raced = true;
            DB::table('grants')->where('permission_id', $archive)->delete();
        }
    });

    Event::fake(WARDEN_EVENTS);

    $this->warden->disallow($this->user)->to(['publish', 'archive']);

    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->permissions->pluck('name')->all() === ['publish']
        && count($event->grants) === 1);
});

it('counts and announces only the assignments its own deletes removed when another write takes one first', function (): void {
    $this->warden->assign(['editor', 'auditor'])->to($this->user);
    $auditor = Role::query()->where('name', 'auditor')->sole()->getKey();
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$raced, $auditor): void {
        if (! $raced && str_starts_with(strtolower($query), 'delete')) {
            $raced = true;
            DB::table('assigned_roles')->where('role_id', $auditor)->delete();
        }
    });

    Event::fake(WARDEN_EVENTS);

    $retract = $this->warden->retract(['editor', 'auditor'])->from($this->user);

    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->roles->pluck('name')->all() === ['editor']
        && count($event->assignments) === 1);
    expect($retract->retractedCount())->toBe(1);
});

it('announces a condition edit with the rule before and after', function (): void {
    $permission = Permission::query()->create([
        'name' => 'view',
        'entity_type' => Account::class,
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Draft']]]]],
    ])->refresh();

    $updates = [];
    Event::listen(PermissionUpdated::class, function (PermissionUpdated $event) use (&$updates): void {
        $updates[] = $event;
    });

    $permission->update([
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Published']]]]],
    ]);

    $snapshot = fn (string $value): array => [
        'v' => 1,
        'key' => $permission->getKey(),
        'name' => 'view',
        'title' => 'View accounts',
        'entity_type' => Account::class,
        'entity_id' => null,
        'only_owned' => false,
        'scope' => null,
        'conditions' => ['g' => ['i' => [['and', ['c' => 'name', 'o' => '=', 't' => 'value', 'v' => $value]]], 't' => 'group'], 'v' => 1],
    ];

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->permission->is($permission))->toBeTrue()
        ->and($updates[0]->before)->toBe($snapshot('Draft'))
        ->and($updates[0]->after)->toBe($snapshot('Published'))
        ->and($updates[0]->changed)->toBe(['conditions'])
        ->and($updates[0]->actor)->toBeNull();
});

it('reports the rule an edit replaced as unreadable when it could not be read', function (): void {
    $permission = Permission::query()->create(['name' => 'view', 'entity_type' => Account::class]);
    DB::table('permissions')->where('id', $permission->getKey())->update(['options' => 'null']);
    $permission->refresh();

    $updates = [];
    Event::listen(PermissionUpdated::class, function (PermissionUpdated $event) use (&$updates): void {
        $updates[] = $event;
    });

    $permission->update([
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Published']]]]],
    ]);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->before['conditions'])->toBe(['unreadable' => 'null'])
        ->and($updates[0]->after['conditions'])->toBe(['g' => ['i' => [['and', ['c' => 'name', 'o' => '=', 't' => 'value', 'v' => 'Published']]], 't' => 'group'], 'v' => 1])
        ->and($updates[0]->changed)->toBe(['conditions']);
});

it('announces a role rename with its name before and after', function (): void {
    $role = Role::query()->create(['name' => 'editor'])->refresh();

    $updates = [];
    Event::listen(RoleUpdated::class, function (RoleUpdated $event) use (&$updates): void {
        $updates[] = $event;
    });

    $role->update(['name' => 'chief-editor']);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->role->is($role))->toBeTrue()
        ->and($updates[0]->before)->toBe(['v' => 1, 'key' => $role->getKey(), 'name' => 'editor', 'title' => 'Editor', 'scope' => null])
        ->and($updates[0]->after)->toBe(['v' => 1, 'key' => $role->getKey(), 'name' => 'chief-editor', 'title' => 'Editor', 'scope' => null])
        ->and($updates[0]->changed)->toBe(['name']);
});

it('announces a title edit as the only change, and several changes in snapshot order', function (): void {
    $permission = Permission::query()->create(['name' => 'delete-accounts'])->refresh();

    $changes = [];
    Event::listen(PermissionUpdated::class, function (PermissionUpdated $event) use (&$changes): void {
        $changes[] = $event->changed;
    });

    $permission->update(['title' => 'See accounts']);
    $permission->update(['title' => 'Remove accounts', 'name' => 'remove-accounts']);

    expect($changes)->toBe([['title'], ['name', 'title']]);
});

it('stays silent when a save leaves the snapshot as it was', function (): void {
    $permission = Permission::query()->create([
        'name' => 'view',
        'entity_type' => Account::class,
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Draft']]]]],
    ])->refresh();
    $role = Role::query()->create(['name' => 'editor'])->refresh();

    $saves = 0;
    Event::listen(['eloquent.updated: '.Permission::class, 'eloquent.updated: '.Role::class], function () use (&$saves): void {
        $saves++;
    });
    $announced = [];
    Event::listen([PermissionUpdated::class, RoleUpdated::class], function (object $event) use (&$announced): void {
        $announced[] = $event;
    });

    $this->travel(1)->minutes();

    $permission->save();
    $permission->touch();
    $role->touch();
    $permission->update([
        'options' => ['g' => ['i' => [['or', ['v' => 'Draft', 'o' => '=', 'c' => 'name', 't' => 'value']]], 't' => 'group'], 'v' => 1],
    ]);
    DB::table('permissions')->where('id', $permission->getKey())->update(['identity_key' => 'stale']);
    $permission->refresh()->save();

    expect($saves)->toBe(4)
        ->and($announced)->toBeEmpty();
});

it('photographs a partially loaded permission from its whole row', function (): void {
    $permission = Permission::query()->create(['name' => 'delete-accounts', 'entity_type' => Account::class])->refresh();

    $updates = [];
    Event::listen(PermissionUpdated::class, function (PermissionUpdated $event) use (&$updates): void {
        $updates[] = $event;
    });

    Permission::query()->select(['id', 'title'])->sole()->update(['title' => 'Remove accounts']);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->before)->toBe(PermissionSnapshot::of($permission))
        ->and($updates[0]->after)->toBe([...PermissionSnapshot::of($permission), 'title' => 'Remove accounts'])
        ->and($updates[0]->changed)->toBe(['title']);
});

it('photographs a partially loaded role from its whole row, a column it never read included', function (): void {
    $role = Role::query()->create(['name' => 'editor'])->refresh();

    $updates = [];
    Event::listen(RoleUpdated::class, function (RoleUpdated $event) use (&$updates): void {
        $updates[] = $event;
    });

    Role::query()->select(['id'])->sole()->update(['title' => 'Chief editor']);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->before)->toBe(RoleSnapshot::of($role))
        ->and($updates[0]->after)->toBe([...RoleSnapshot::of($role), 'title' => 'Chief editor'])
        ->and($updates[0]->changed)->toBe(['title']);
});

it('carries the acting user on catalog edits', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $permission = Permission::query()->create(['name' => 'edit-site'])->refresh();
    $role = Role::query()->create(['name' => 'editor'])->refresh();
    $this->actingAs($admin);

    Event::fake(WARDEN_EVENTS);

    $permission->update(['title' => 'Edit the site']);
    $role->update(['title' => 'Site editor']);

    Event::assertDispatched(PermissionUpdated::class, fn (PermissionUpdated $event): bool => $event->actor?->is($admin) === true);
    Event::assertDispatched(RoleUpdated::class, fn (RoleUpdated $event): bool => $event->actor?->is($admin) === true);
});

it('goes quiet on catalog edits when events are disabled', function (): void {
    $permission = Permission::query()->create(['name' => 'edit-site'])->refresh();
    $role = Role::query()->create(['name' => 'editor'])->refresh();
    config()->set('warden.events_enabled', false);

    Event::fake(WARDEN_EVENTS);

    $permission->update(['name' => 'edit-pages']);
    $role->update(['name' => 'chief-editor']);

    Event::assertNotDispatched(PermissionUpdated::class);
    Event::assertNotDispatched(RoleUpdated::class);
});

it('keeps the snapshots of an edit intact through serialization', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $permission = Permission::query()->create(['name' => 'edit-site'])->refresh();
    $role = Role::query()->create(['name' => 'editor'])->refresh();
    $this->actingAs($admin);

    $announced = [];
    Event::listen([PermissionUpdated::class, RoleUpdated::class], function (object $event) use (&$announced): void {
        $announced[] = serialize($event);
    });

    $permission->update(['title' => 'Edit the site']);
    $role->update(['name' => 'chief-editor']);
    DB::table('permissions')->where('id', $permission->getKey())->update(['title' => 'Edited later']);

    [$edit, $rename] = array_map(unserialize(...), $announced);

    expect($edit)->toBeInstanceOf(PermissionUpdated::class)
        ->and($edit->permission->getAttribute('title'))->toBe('Edited later')
        ->and($edit->before['title'])->toBe('Edit site')
        ->and($edit->after['title'])->toBe('Edit the site')
        ->and($edit->changed)->toBe(['title'])
        ->and($edit->actor?->is($admin))->toBeTrue()
        ->and($rename)->toBeInstanceOf(RoleUpdated::class)
        ->and($rename->role->is($role))->toBeTrue()
        ->and($rename->before['name'])->toBe('editor')
        ->and($rename->after['name'])->toBe('chief-editor')
        ->and($rename->actor?->is($admin))->toBeTrue();
});

it('lets a listener of a catalog edit read the cache the edit left', function (): void {
    config()->set('warden.cache.enabled', true);
    $account = Account::query()->create(['name' => 'Acme']);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Other');

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();

    $seen = [];
    Event::listen(PermissionUpdated::class, function () use (&$seen, $account): void {
        $seen[] = Gate::forUser($this->user)->allows('view', $account);
    });

    Permission::query()->where('name', 'view')->sole()->update([
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'Acme']]]]],
    ]);

    expect($seen)->toBe([true]);
});
