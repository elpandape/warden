<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\FixedActorResolver;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

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
