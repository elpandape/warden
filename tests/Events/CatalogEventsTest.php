<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\FixedActorResolver;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingPermission;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToPermissions;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('names the acting user on every catalog event', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    Event::fake([RoleCreated::class, RoleDeleted::class, PermissionCreated::class, PermissionDeleted::class]);

    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->assign('admin')->to($this->user);

    Role::query()->where('name', 'admin')->sole()->delete();
    Permission::query()->where('name', 'edit-site')->sole()->delete();

    Event::assertDispatched(RoleCreated::class, fn (RoleCreated $event): bool => $event->actor?->is($admin) === true);
    Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $event): bool => $event->actor?->is($admin) === true);
    Event::assertDispatched(PermissionCreated::class, fn (PermissionCreated $event): bool => $event->actor?->is($admin) === true);
    Event::assertDispatched(PermissionDeleted::class, fn (PermissionDeleted $event): bool => $event->actor?->is($admin) === true);
});

it('names the catalog actor through a configured resolver', function (): void {
    $auditor = User::query()->create(['name' => 'Auditor']);
    config()->set('warden.actor_resolver', FixedActorResolver::class);

    Event::fake([RoleCreated::class, RoleDeleted::class, PermissionCreated::class, PermissionDeleted::class]);

    Role::query()->create(['name' => 'editor'])->delete();
    Permission::query()->create(['name' => 'publish'])->delete();

    Event::assertDispatched(RoleCreated::class, fn (RoleCreated $event): bool => $event->actor?->is($auditor) === true);
    Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $event): bool => $event->actor?->is($auditor) === true);
    Event::assertDispatched(PermissionCreated::class, fn (PermissionCreated $event): bool => $event->actor?->is($auditor) === true);
    Event::assertDispatched(PermissionDeleted::class, fn (PermissionDeleted $event): bool => $event->actor?->is($auditor) === true);
});

it('leaves the catalog actor null when nobody is acting', function (): void {
    Event::fake([RoleCreated::class, PermissionDeleted::class]);

    Role::query()->create(['name' => 'editor']);
    Permission::query()->create(['name' => 'publish'])->delete();

    Event::assertDispatched(RoleCreated::class, fn (RoleCreated $event): bool => ! $event->actor instanceof Model);
    Event::assertDispatched(PermissionDeleted::class, fn (PermissionDeleted $event): bool => ! $event->actor instanceof Model);
});

it('still builds catalog events without an actor', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);

    $deleted = new RoleDeleted($role);

    expect((new RoleCreated($role))->actor)->toBeNull()
        ->and((new PermissionCreated($permission))->actor)->toBeNull()
        ->and((new PermissionDeleted($permission))->actor)->toBeNull()
        ->and($deleted->actor)->toBeNull()
        ->and($deleted->heldGrants)->toBeEmpty()
        ->and($deleted->heldRoles)->toBeEmpty();
});

it('restores a deleted role and its actor from a queued RoleDeleted', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    $queued = [];
    Event::listen(RoleDeleted::class, function (RoleDeleted $event) use (&$queued): void {
        $queued[] = serialize($event);
    });

    $role = Role::query()->create(['name' => 'editor']);
    $key = $role->getKey();

    $role->delete();

    $restored = unserialize($queued[0]);

    expect($restored)->toBeInstanceOf(RoleDeleted::class)
        ->and($restored->role)->toBeInstanceOf(Role::class)
        ->and($restored->role->getKey())->toBe($key)
        ->and($restored->role->getAttribute('name'))->toBe('editor')
        ->and($restored->role->exists)->toBeFalse()
        ->and($restored->actor?->is($admin))->toBeTrue()
        ->and($restored->heldGrants)->toBeEmpty()
        ->and($restored->heldRoles)->toBeEmpty();
});

it('restores a deleted permission and its actor from a queued PermissionDeleted', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    $queued = [];
    Event::listen(PermissionDeleted::class, function (PermissionDeleted $event) use (&$queued): void {
        $queued[] = serialize($event);
    });

    $permission = Permission::query()->create(['name' => 'publish']);
    $key = $permission->getKey();

    $permission->delete();

    $restored = unserialize($queued[0]);

    expect($restored)->toBeInstanceOf(PermissionDeleted::class)
        ->and($restored->permission)->toBeInstanceOf(Permission::class)
        ->and($restored->permission->getKey())->toBe($key)
        ->and($restored->permission->getAttribute('name'))->toBe('publish')
        ->and($restored->permission->exists)->toBeFalse()
        ->and($restored->actor?->is($admin))->toBeTrue();
});

it('queues the catalog actor without any of its attributes', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    $queued = [];
    Event::listen([RoleDeleted::class, PermissionDeleted::class], function (object $event) use (&$queued): void {
        $queued[] = serialize($event);
    });

    Role::query()->create(['name' => 'editor'])->delete();
    Permission::query()->create(['name' => 'publish'])->delete();

    expect($queued)->toHaveCount(2)
        ->and($queued[0])->not->toContain('Admin')
        ->and($queued[1])->not->toContain('Admin');
});

it('restores a key-only stand-in for a catalog actor whose row is gone', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->actingAs($admin);

    $queued = [];
    Event::listen([RoleDeleted::class, PermissionDeleted::class], function (object $event) use (&$queued): void {
        $queued[] = serialize($event);
    });

    Role::query()->create(['name' => 'editor'])->delete();
    Permission::query()->create(['name' => 'publish'])->delete();
    $admin->delete();

    $roleDeleted = unserialize($queued[0]);
    $permissionDeleted = unserialize($queued[1]);

    expect($roleDeleted->actor)->toBeInstanceOf(User::class)
        ->and($roleDeleted->actor?->exists)->toBeFalse()
        ->and($roleDeleted->actor?->getAttributes())->toBe(['id' => $admin->getKey()])
        ->and($permissionDeleted->actor)->toBeInstanceOf(User::class)
        ->and($permissionDeleted->actor?->exists)->toBeFalse()
        ->and($permissionDeleted->actor?->getAttributes())->toBe(['id' => $admin->getKey()]);
});

it('restores an absent catalog actor as null from the queue', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);

    expect(unserialize(serialize(new RoleDeleted($role)))->actor)->toBeNull()
        ->and(unserialize(serialize(new PermissionDeleted($permission)))->actor)->toBeNull();
});

it('queues the catalog row of a deletion without its loaded relations', function (): void {
    $role = Role::query()->create(['name' => 'editor'])->load('nestedRoles');
    $permission = Permission::query()->create(['name' => 'publish'])->load('roles');

    $restoredRole = unserialize(serialize(new RoleDeleted($role)))->role;
    $restoredPermission = unserialize(serialize(new PermissionDeleted($permission)))->permission;

    expect($restoredRole)->toBeInstanceOf(Role::class)
        ->and($restoredRole->getAttributes())->toBe($role->getAttributes())
        ->and($restoredRole->getRelations())->toBeEmpty()
        ->and($role->relationLoaded('nestedRoles'))->toBeTrue()
        ->and($restoredPermission)->toBeInstanceOf(Permission::class)
        ->and($restoredPermission->getAttributes())->toBe($permission->getAttributes())
        ->and($restoredPermission->getRelations())->toBeEmpty()
        ->and($permission->relationLoaded('roles'))->toBeTrue();
});

it('carries the rows a RoleDeleted describes across a queue by value', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $ends = CarbonImmutable::parse('2026-10-01 12:00:00');

    $heldGrants = [[
        'permission' => [
            'v' => 1,
            'key' => 1,
            'name' => 'publish',
            'title' => 'Publish',
            'entity_type' => null,
            'entity_id' => null,
            'only_owned' => false,
            'scope' => null,
            'conditions' => null,
        ],
        'forbidden' => false,
        'scope' => null,
        'expires_at' => $ends,
    ]];

    $heldRoles = [[
        'role' => ['v' => 1, 'key' => 2, 'name' => 'auditor', 'title' => 'Auditor', 'scope' => null],
        'scope' => 7,
        'restricted_to_type' => null,
        'restricted_to_id' => null,
        'expires_at' => null,
    ]];

    $restored = unserialize(serialize(new RoleDeleted($role, heldGrants: $heldGrants, heldRoles: $heldRoles)));

    expect($restored->heldGrants)->toEqual($heldGrants)
        ->and($restored->heldGrants[0]['expires_at']?->equalTo($ends))->toBeTrue()
        ->and($restored->heldRoles)->toBe($heldRoles);
});

it('carries each removed grant and its end date in a permission cascade', function (): void {
    withForeignKeys();

    $ends = CarbonImmutable::now()->addDay()->startOfSecond();
    $luis = User::query()->create(['name' => 'Luis']);
    $ana = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->until($ends)->to('edit-site');
    $this->warden->allow($luis)->to('edit-site');
    $this->warden->forbid($ana)->to('edit-site');

    Event::fake([PermissionRevoked::class, PermissionUnforbidden::class]);

    $permission = Permission::query()->where('name', 'edit-site')->sole();
    $permission->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 2);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true
        && count($event->grants) === 1
        && $event->grants[0] instanceof GrantRemoval
        && $event->grants[0]->permission->is($permission)
        && $event->grants[0]->expiresAt?->equalTo($ends) === true);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($luis) === true
        && count($event->grants) === 1
        && $event->grants[0]->permission->is($permission)
        && ! $event->grants[0]->expiresAt instanceof CarbonImmutable);
    Event::assertDispatched(PermissionUnforbidden::class, fn (PermissionUnforbidden $event): bool => $event->authority?->is($ana) === true
        && count($event->grants) === 1
        && $event->grants[0]->permission->is($permission)
        && ! $event->grants[0]->expiresAt instanceof CarbonImmutable);

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('announces a cascaded grant that had already expired, with the date it had', function (): void {
    withForeignKeys();

    $ends = CarbonImmutable::now()->addDay()->startOfSecond();
    $this->warden->allow($this->user)->until($ends)->to('edit-site');

    $this->travel(2)->days();

    Event::fake([PermissionRevoked::class]);

    Permission::query()->where('name', 'edit-site')->sole()->delete();

    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority?->is($this->user) === true
        && count($event->grants) === 1
        && $event->grants[0]->expiresAt?->equalTo($ends) === true);

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps an everyone-grant null while naming a holder its tenant hides', function (): void {
    withForeignKeys();

    $this->warden->tenant()->to(7);
    $this->warden->allow('auditor')->to('publish');
    $this->warden->allowEveryone()->to('publish');

    $this->warden->tenant()->to(8);

    Event::fake([PermissionRevoked::class]);

    Permission::query()->withoutGlobalScopes()->where('name', 'publish')->sole()->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 2);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => $event->authority instanceof Role
        && $event->authority->getAttribute('name') === 'auditor'
        && $event->scope === 7
        && count($event->grants) === 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => ! $event->authority instanceof Model
        && $event->scope === 7
        && count($event->grants) === 1);

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('announces nothing for a cascaded holder it cannot name', function (): void {
    withForeignKeys();

    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->allow($luis)->to('publish');

    $permission = Permission::query()->where('name', 'publish')->sole();

    DB::table('grants')->insert([
        'permission_id' => $permission->getKey(),
        'entity_type' => 'ghost',
        'entity_id' => 1,
        'forbidden' => false,
        'scope' => null,
    ]);
    DB::table('users')->where('id', $luis->getKey())->delete();

    Log::spy();
    Event::fake([PermissionRevoked::class]);

    $permission->delete();

    Event::assertNotDispatched(PermissionRevoked::class);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => $message === 'Warden: no model class maps the morph type [ghost], so its rows cannot be named.',
    );
    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('announces no revoke for an old grant that names a type but no holder', function (): void {
    withForeignKeys();

    $this->warden->allowEveryone()->to('publish');
    $permission = Permission::query()->where('name', 'publish')->sole();

    DB::table('grants')->insert([
        'permission_id' => $permission->getKey(),
        'entity_type' => $this->user->getMorphClass(),
        'entity_id' => null,
        'forbidden' => false,
        'scope' => null,
    ]);

    Event::fake([PermissionRevoked::class]);

    $permission->delete();

    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
    Event::assertDispatched(PermissionRevoked::class, fn (PermissionRevoked $event): bool => ! $event->authority instanceof Model);
});

it('announces a permission cascade in grant-key order, whatever order the engine reads it in', function (): void {
    withForeignKeys();

    $ana = User::query()->create(['name' => 'Ana']);
    $luis = User::query()->create(['name' => 'Luis']);

    $this->warden->allow($luis)->to('publish');
    $this->warden->allow($ana)->to('publish');

    Event::fake([PermissionRevoked::class]);

    Permission::query()->where('name', 'publish')->sole()->delete();

    $revoked = Event::dispatched(PermissionRevoked::class)
        ->map(fn (array $arguments): mixed => $arguments[0]->authority?->getKey())
        ->values()
        ->all();

    expect($revoked)->toBe([$luis->getKey(), $ana->getKey()]);
});

it('reads nothing to announce a permission cascade when events are disabled', function (): void {
    withForeignKeys();
    config()->set('warden.events_enabled', false);
    $this->warden->allow($this->user)->to('publish');
    $permission = Permission::query()->where('name', 'publish')->sole();

    Event::fake([PermissionDeleted::class, PermissionRevoked::class]);
    DB::enableQueryLog();

    $permission->delete();

    $announcing = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'expires_at'));

    expect($announcing->all())->toBeEmpty()
        ->and(Grant::query()->withoutGlobalScopes()->exists())->toBeFalse();
    Event::assertNotDispatched(PermissionDeleted::class);
    Event::assertNotDispatched(PermissionRevoked::class);
});

it('leaves a soft-deleted permission and its grants in place and announces no cascade', function (): void {
    withForeignKeys();
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);

    $this->warden->allow($this->user)->to('publish');

    Event::fake([PermissionDeleted::class, PermissionRevoked::class]);

    SoftDeletingPermission::query()->where('name', 'publish')->sole()->delete();

    Event::assertDispatched(PermissionDeleted::class);
    Event::assertNotDispatched(PermissionRevoked::class);
    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets a listener of a soft-deleted permission already see it grant nothing', function (): void {
    withForeignKeys();
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    config()->set('warden.cache.enabled', true);

    $this->warden->allow($this->user)->to('publish');
    $seen = [];

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();

    Event::listen(PermissionDeleted::class, function () use (&$seen): void {
        $seen[] = Gate::forUser($this->user)->allows('publish');
    });

    SoftDeletingPermission::query()->where('name', 'publish')->sole()->delete();

    expect($seen)->toBe([false]);
});

it('invalidates the tenant a soft-deleted permission was granted under, not only its own', function (): void {
    withForeignKeys();
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    config()->set('warden.cache.enabled', true);
    $this->warden->tenant()->onlyRelations();

    $created = SoftDeletingPermission::query()->create(['name' => 'report']);
    DB::table('permissions')->where('id', $created->getKey())->update(['scope' => 5]);
    $report = SoftDeletingPermission::query()->whereKey($created->getKey())->sole();

    $this->warden->tenant()->to(7);
    $this->warden->allow($this->user)->to($report);

    expect($report->getAttribute('scope'))->toBe(5)
        ->and(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBe(7)
        ->and(Gate::forUser($this->user)->allows('report'))->toBeTrue();

    $report->delete();

    expect(Gate::forUser($this->user)->allows('report'))->toBeFalse();
});
