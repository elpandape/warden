<?php

declare(strict_types=1);

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
