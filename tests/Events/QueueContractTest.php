<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\PermissionDeleted;
use ElPandaPe\Warden\Events\PermissionForbidden;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\PermissionRevoked;
use ElPandaPe\Warden\Events\PermissionsSynced;
use ElPandaPe\Warden\Events\PermissionUnforbidden;
use ElPandaPe\Warden\Events\PermissionUpdated;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Events\RolesSynced;
use ElPandaPe\Warden\Events\RoleUpdated;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingPermission;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToPermissions;
use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;
use function ElPandaPe\Warden\Tests\deleteWardenRows;
use function ElPandaPe\Warden\Tests\payloadOf;
use function ElPandaPe\Warden\Tests\payloadWithout;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('restores a grant event queued under 3.0, without the entries 3.1 added', function (): void {
    Event::fake([PermissionGranted::class]);

    $this->warden->allow($this->user)->to('publish');

    $restored = unserialize(payloadWithout(Event::dispatched(PermissionGranted::class)->sole()[0], 'grants', 'operation'));

    expect($restored)->toBeInstanceOf(PermissionGranted::class)
        ->and($restored->authority->is($this->user))->toBeTrue()
        ->and($restored->permissions->sole()->getAttribute('name'))->toBe('publish')
        ->and(fn (): array => $restored->grants)->toThrow(Error::class, 'must not be accessed before initialization')
        ->and($restored->grants ?? 'drained')->toBe('drained');
});

it('restores an assignment event queued under 3.0, without the entries 3.1 added', function (): void {
    Event::fake([RoleAssigned::class]);

    $this->warden->assign('editor')->to($this->user);

    $restored = unserialize(payloadWithout(Event::dispatched(RoleAssigned::class)->sole()[0], 'assignments', 'operation'));

    expect($restored->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and(fn (): array => $restored->assignments)->toThrow(Error::class, 'must not be accessed before initialization')
        ->and($restored->assignments ?? 'drained')->toBe('drained');
});

it('restores a catalog event queued under 3.0, without the actor 3.1 added', function (): void {
    Event::fake([RoleCreated::class]);

    Role::query()->create(['name' => 'editor']);

    $restored = unserialize(payloadWithout(Event::dispatched(RoleCreated::class)->sole()[0], 'actor', 'operation'));

    expect($restored->role->getAttribute('name'))->toBe('editor')
        ->and(fn (): ?Model => $restored->actor)->toThrow(Error::class, 'must not be accessed before initialization')
        ->and($restored->actor ?? 'drained')->toBe('drained');
});

it('fails a deletion event queued under 3.0 with a TypeError, even while its row exists', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);

    $roleDeleted = payloadOf(RoleDeleted::class, [
        'role' => new ModelIdentifier($role::class, $role->getKey(), [], $role->getConnectionName()),
    ]);
    $permissionDeleted = payloadOf(PermissionDeleted::class, [
        'permission' => new ModelIdentifier($permission::class, $permission->getKey(), [], $permission->getConnectionName()),
    ]);

    expect(fn (): mixed => unserialize($roleDeleted))->toThrow(TypeError::class, 'Cannot assign '.ModelIdentifier::class)
        ->and(fn (): mixed => unserialize($permissionDeleted))->toThrow(TypeError::class, 'Cannot assign '.ModelIdentifier::class);
});

it('keeps the permissions of a queued grant by value once their rows are gone', function (): void {
    Event::fake([PermissionGranted::class]);

    $this->warden->allow($this->user)->to(['publish', 'archive']);

    $payload = serialize(Event::dispatched(PermissionGranted::class)->sole()[0]);
    deleteWardenRows();
    $restored = unserialize($payload);

    expect(Permission::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and($restored->permissions::class)->toBe(Collection::class)
        ->and($restored->permissions->pluck('name')->all())->toBe(['publish', 'archive']);
});

it('keeps the roles and assignments of a queued assignment by value once their rows are gone', function (): void {
    Event::fake([RoleAssigned::class]);

    $this->warden->assign('editor')->until(Carbon::parse('2030-12-31 23:59:59'))->to($this->user);

    $payload = serialize(Event::dispatched(RoleAssigned::class)->sole()[0]);
    deleteWardenRows();
    $restored = unserialize($payload);

    expect(Role::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and($restored->roles::class)->toBe(Collection::class)
        ->and($restored->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and($restored->assignments[0]->role)->toBe($restored->roles->sole())
        ->and($restored->assignments[0]->created)->toBeTrue()
        ->and($restored->assignments[0]->expiresAt?->toDateTimeString())->toBe('2030-12-31 23:59:59');
});

it('keeps the permissions and removals of a queued revoke by value once their rows are gone', function (): void {
    $this->warden->allow($this->user)->until(Carbon::parse('2030-12-31 23:59:59'))->to('publish');

    Event::fake([PermissionRevoked::class]);

    $this->warden->disallow($this->user)->to('publish');

    $payload = serialize(Event::dispatched(PermissionRevoked::class)->sole()[0]);
    deleteWardenRows();
    $restored = unserialize($payload);

    expect(Permission::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and($restored->permissions::class)->toBe(Collection::class)
        ->and($restored->permissions->sole()->getAttribute('name'))->toBe('publish')
        ->and($restored->grants[0]->permission)->toBe($restored->permissions->sole())
        ->and($restored->grants[0]->expiresAt?->toDateTimeString())->toBe('2030-12-31 23:59:59');
});

it('keeps the diff of a queued role sync by value once its rows are gone', function (): void {
    $this->warden->assign(['admin', 'editor'])->to($this->user);

    Event::fake([RolesSynced::class]);

    $this->warden->sync($this->user)->roles(['editor', 'writer']);

    $payload = serialize(Event::dispatched(RolesSynced::class)->sole()[0]);
    deleteWardenRows();
    $changes = unserialize($payload)->changes;

    expect(Role::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and([$changes->attached::class, $changes->detached::class, $changes->kept::class])->each->toBe(Collection::class)
        ->and($changes->attached->pluck('name')->all())->toBe(['writer'])
        ->and($changes->detached->pluck('name')->all())->toBe(['admin'])
        ->and($changes->kept->pluck('name')->all())->toBe(['editor']);
});

it('fails a queued catalog edit whose row was deleted since', function (): void {
    $role = Role::query()->create(['name' => 'editor'])->refresh();
    $permission = Permission::query()->create(['name' => 'publish'])->refresh();

    Event::fake([RoleUpdated::class, PermissionUpdated::class]);

    $role->update(['name' => 'chief-editor']);
    $permission->update(['title' => 'Publish it']);

    $rename = serialize(Event::dispatched(RoleUpdated::class)->sole()[0]);
    $edit = serialize(Event::dispatched(PermissionUpdated::class)->sole()[0]);
    deleteWardenRows();

    expect(fn (): mixed => unserialize($rename))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => unserialize($edit))->toThrow(ModelNotFoundException::class);
});

it('restores a queued catalog edit whose row was trashed since, in the trash', function (): void {
    addSoftDeletesToRoles();
    addSoftDeletesToPermissions();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    Context::resolve()->setModelClass('permission', SoftDeletingPermission::class);
    $role = SoftDeletingRole::query()->create(['name' => 'editor'])->refresh();
    $permission = SoftDeletingPermission::query()->create(['name' => 'publish'])->refresh();

    Event::fake([RoleUpdated::class, PermissionUpdated::class]);

    $role->update(['name' => 'chief-editor']);
    $permission->update(['title' => 'Publish it']);

    $rename = serialize(Event::dispatched(RoleUpdated::class)->sole()[0]);
    $edit = serialize(Event::dispatched(PermissionUpdated::class)->sole()[0]);
    $role->delete();
    $permission->delete();

    $restoredRole = unserialize($rename)->role;
    $restoredPermission = unserialize($edit)->permission;

    expect($restoredRole)->toBeInstanceOf(SoftDeletingRole::class)
        ->and($restoredRole->trashed())->toBeTrue()
        ->and($restoredRole->getAttribute('name'))->toBe('chief-editor')
        ->and($restoredPermission)->toBeInstanceOf(SoftDeletingPermission::class)
        ->and($restoredPermission->trashed())->toBeTrue()
        ->and($restoredPermission->getAttribute('title'))->toBe('Publish it');
});

it('refuses to strip from a queue payload a key the event does not serialize', function (): void {
    $event = new RoleCreated(Role::query()->create(['name' => 'editor']));

    expect(fn (): string => payloadWithout($event, 'actor', 'assignments', 'grants'))
        ->toThrow(LogicException::class, 'The queue payload of '.RoleCreated::class.' has no assignments, grants.')
        ->and(payloadWithout($event))->toBe(serialize($event));
});

it('keeps the operation of a queued event, whichever way the event serializes', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);
    $operation = (string) Str::ulid();

    expect(unserialize(serialize(new RoleDeleted($role, operation: $operation)))->operation)->toBe($operation)
        ->and(unserialize(serialize(new PermissionDeleted($permission, operation: $operation)))->operation)->toBe($operation)
        ->and(unserialize(serialize(new RoleCreated($role, operation: $operation)))->operation)->toBe($operation);
});

it('restores a RoleDeleted and a PermissionDeleted queued by 3.1 with no operation', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);
    $operation = (string) Str::ulid();

    $roleDeleted = unserialize(payloadWithout(new RoleDeleted($role, operation: $operation), 'operation'));
    $permissionDeleted = unserialize(payloadWithout(new PermissionDeleted($permission, operation: $operation), 'operation'));

    expect($roleDeleted)->toBeInstanceOf(RoleDeleted::class)
        ->and($roleDeleted->operation)->toBeNull()
        ->and($roleDeleted->role->getAttribute('name'))->toBe('editor')
        ->and($permissionDeleted)->toBeInstanceOf(PermissionDeleted::class)
        ->and($permissionDeleted->operation)->toBeNull()
        ->and($permissionDeleted->permission->getAttribute('name'))->toBe('publish');
});

it('leaves the operation of a generic event queued by 3.1 unset, which ?? reads as null', function (): void {
    $role = Role::query()->create(['name' => 'editor']);

    $restored = unserialize(payloadWithout(new RoleCreated($role, operation: (string) Str::ulid()), 'operation'));

    expect($restored->role->is($role))->toBeTrue()
        ->and(fn (): ?string => $restored->operation)->toThrow(Error::class, 'must not be accessed before initialization')
        ->and($restored->operation ?? null)->toBeNull();
});

it('restores a payload that carries a key this version does not know', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'publish']);
    $operation = (string) Str::ulid();
    $later = ['arrivedInALaterMinor' => true];

    $roleDeleted = unserialize(payloadOf(RoleDeleted::class, [...new RoleDeleted($role, operation: $operation)->__serialize(), ...$later]));
    $permissionDeleted = unserialize(payloadOf(PermissionDeleted::class, [...new PermissionDeleted($permission, operation: $operation)->__serialize(), ...$later]));
    $roleCreated = unserialize(payloadOf(RoleCreated::class, [...new RoleCreated($role, operation: $operation)->__serialize(), ...$later]));

    expect($roleDeleted->role->getAttribute('name'))->toBe('editor')
        ->and($roleDeleted->operation)->toBe($operation)
        ->and($permissionDeleted->permission->getAttribute('name'))->toBe('publish')
        ->and($permissionDeleted->operation)->toBe($operation)
        ->and($roleCreated->role->is($role))->toBeTrue()
        ->and($roleCreated->operation)->toBe($operation);
});

it('queues the roles of an assignment without the relations loaded on them', function (): void {
    $this->warden->allow('editor')->to('publish-sealed-minutes');
    $editor = Role::query()->where('name', 'editor')->sole()->load('permissions');
    $heard = null;

    Event::listen(RoleAssigned::class, function (RoleAssigned $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->assign($editor)->to($this->user);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard->roles->sole())->toBe($editor)
        ->and($heard->assignments[0]->role)->toBe($editor)
        ->and($editor->relationLoaded('permissions'))->toBeTrue()
        ->and(array_keys($heard->__serialize()))->toBe(['authority', 'roles', 'scope', 'restrictedTo', 'actor', 'assignments', 'operation'])
        ->and($payload)->not->toContain('publish-sealed-minutes')
        ->and($queued->roles->sole()->getRelations())->toBe([])
        ->and($queued->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and($queued->assignments[0]->role)->toBe($queued->roles->sole());
});

it('queues the roles of a retraction without the relations loaded on them', function (): void {
    $acme = Account::query()->create(['name' => 'Acme']);
    $this->warden->allow('editor')->to('publish-sealed-minutes');
    $this->warden->assign('editor')->on($acme)->to($this->user);
    $this->warden->assign('sealed-minutes-desk')->to($acme);
    $editor = Role::query()->where('name', 'editor')->sole()->load('permissions');
    $heard = null;

    Event::listen('eloquent.retrieved: '.Account::class, fn (Account $account): Account => $account->load('roles'));
    Event::listen(RoleRetracted::class, function (RoleRetracted $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->retract($editor)->from($this->user);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard->roles->sole())->toBe($editor)
        ->and($heard->assignments[0]->role)->toBe($editor)
        ->and($editor->relationLoaded('permissions'))->toBeTrue()
        ->and($heard->assignments[0]->restrictedTo?->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('publish-sealed-minutes')
        ->and($payload)->not->toContain('sealed-minutes-desk')
        ->and($queued->roles->sole()->getRelations())->toBe([])
        ->and($queued->assignments[0]->role)->toBe($queued->roles->sole())
        ->and($queued->assignments[0]->restrictedTo?->getRelations())->toBe([])
        ->and($queued->assignments[0]->restrictedTo?->getAttribute('name'))->toBe('Acme');
});

it('queues the permissions of a grant without the relations loaded on them', function (string $verb, string $announced): void {
    $this->warden->allow('sealed-minutes-clerk')->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole()->load('roles');
    $heard = null;

    Event::listen($announced, function (PermissionGranted|PermissionForbidden $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->{$verb}($this->user)->to($publish);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard)->toBeInstanceOf($announced)
        ->and($heard->permissions->sole())->toBe($publish)
        ->and($heard->grants[0]->permission)->toBe($publish)
        ->and($publish->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('sealed-minutes-clerk')
        ->and($queued->permissions->sole()->getRelations())->toBe([])
        ->and($queued->grants[0]->permission)->toBe($queued->permissions->sole());
})->with([
    'allow' => ['allow', PermissionGranted::class],
    'forbid' => ['forbid', PermissionForbidden::class],
]);

it('queues the permissions of a removal without the relations loaded on them', function (string $write, string $verb, string $announced): void {
    $this->warden->allow('sealed-minutes-clerk')->to('publish');
    $this->warden->{$write}($this->user)->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole()->load('roles');
    $heard = null;

    Event::listen($announced, function (PermissionRevoked|PermissionUnforbidden $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->{$verb}($this->user)->to($publish);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard)->toBeInstanceOf($announced)
        ->and($heard->permissions->sole())->toBe($publish)
        ->and($heard->grants[0]->permission)->toBe($publish)
        ->and($publish->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('sealed-minutes-clerk')
        ->and($queued->permissions->sole()->getRelations())->toBe([])
        ->and($queued->grants[0]->permission)->toBe($queued->permissions->sole());
})->with([
    'disallow' => ['allow', 'disallow', PermissionRevoked::class],
    'unforbid' => ['forbid', 'unforbid', PermissionUnforbidden::class],
]);

it('queues the permission a delete cascades from without the relations loaded on it', function (): void {
    withForeignKeys();
    $this->warden->allow('sealed-minutes-clerk')->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole()->load('roles');
    $heard = null;
    $deleted = null;

    Event::listen(PermissionRevoked::class, function (PermissionRevoked $event) use (&$heard): void {
        $heard = $event;
    });
    Event::listen(PermissionDeleted::class, function (PermissionDeleted $event) use (&$deleted): void {
        $deleted = $event;
    });

    $publish->delete();

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard->permissions->sole())->toBe($publish)
        ->and($heard->grants[0]->permission)->toBe($publish)
        ->and($deleted?->permission)->toBe($publish)
        ->and($publish->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('sealed-minutes-clerk')
        ->and($queued->authority?->getAttribute('name'))->toBe('sealed-minutes-clerk')
        ->and($queued->permissions->sole()->getRelations())->toBe([])
        ->and($queued->grants[0]->permission)->toBe($queued->permissions->sole());
});

it('queues the diff of a role sync without the relations loaded on its rows', function (): void {
    $this->warden->allow('auditor')->to('publish-sealed-minutes');
    $this->warden->allow('editor')->to('publish-sealed-minutes');
    $this->warden->allow('reviewer')->to('publish-sealed-minutes');
    $this->warden->assign(['auditor', 'reviewer'])->to($this->user);
    $editor = Role::query()->where('name', 'editor')->sole()->load('permissions');
    $heard = null;

    Event::listen('eloquent.retrieved: '.Role::class, fn (Role $role): Role => $role->load('permissions'));
    Event::listen(RolesSynced::class, function (RolesSynced $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->sync($this->user)->roles([$editor, 'reviewer']);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard->changes->attached->sole())->toBe($editor)
        ->and($editor->relationLoaded('permissions'))->toBeTrue()
        ->and($heard->changes->kept->sole()->relationLoaded('permissions'))->toBeTrue()
        ->and($heard->changes->detached->sole()->relationLoaded('permissions'))->toBeTrue()
        ->and(array_keys($heard->__serialize()))->toBe(['authority', 'changes', 'scope', 'actor', 'operation'])
        ->and($payload)->not->toContain('publish-sealed-minutes')
        ->and($queued->changes->attached->sole()->getRelations())->toBe([])
        ->and($queued->changes->attached->sole()->getAttribute('name'))->toBe('editor')
        ->and($queued->changes->kept->sole()->getRelations())->toBe([])
        ->and($queued->changes->kept->sole()->getAttribute('name'))->toBe('reviewer')
        ->and($queued->changes->detached->sole()->getRelations())->toBe([])
        ->and($queued->changes->detached->sole()->getAttribute('name'))->toBe('auditor');
});

it('queues the diff of a permission sync without the relations loaded on its rows', function (string $method, bool $forbidden): void {
    $this->warden->allow('sealed-minutes-clerk')->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole()->load('roles');
    $heard = null;

    Event::listen(PermissionsSynced::class, function (PermissionsSynced $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->sync($this->user)->{$method}([$publish]);

    $payload = serialize($heard);
    $queued = unserialize($payload);

    expect($heard->changes->attached->sole())->toBe($publish)
        ->and($publish->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('sealed-minutes-clerk')
        ->and($queued->forbidden)->toBe($forbidden)
        ->and($queued->changes->attached->sole()->getRelations())->toBe([])
        ->and($queued->changes->attached->sole()->getAttribute('name'))->toBe('publish');
})->with([
    'permissions' => ['permissions', false],
    'forbidden permissions' => ['forbiddenPermissions', true],
]);

it('queues each side of a permission sync diff as a plain collection without the relations loaded on its rows', function (): void {
    $this->warden->allow('sealed-minutes-clerk')->to(['archive', 'publish', 'review']);
    $this->warden->allow($this->user)->to(['archive', 'review']);
    $publish = Permission::query()->where('name', 'publish')->sole()->load('roles');
    $heard = null;

    Event::listen('eloquent.retrieved: '.Permission::class, fn (Permission $permission): Permission => $permission->load('roles'));
    Event::listen(PermissionsSynced::class, function (PermissionsSynced $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->sync($this->user)->permissions([$publish, 'review']);

    $payload = serialize($heard);
    $changes = unserialize($payload)->changes;

    expect($heard->changes->attached->sole())->toBe($publish)
        ->and($publish->relationLoaded('roles'))->toBeTrue()
        ->and($heard->changes->kept->sole()->relationLoaded('roles'))->toBeTrue()
        ->and($heard->changes->detached->sole()->relationLoaded('roles'))->toBeTrue()
        ->and($payload)->not->toContain('sealed-minutes-clerk')
        ->and([$changes->attached::class, $changes->detached::class, $changes->kept::class])->each->toBe(Collection::class)
        ->and($changes->attached->sole()->getRelations())->toBe([])
        ->and($changes->attached->sole()->getAttribute('name'))->toBe('publish')
        ->and($changes->kept->sole()->getRelations())->toBe([])
        ->and($changes->kept->sole()->getAttribute('name'))->toBe('review')
        ->and($changes->detached->sole()->getRelations())->toBe([])
        ->and($changes->detached->sole()->getAttribute('name'))->toBe('archive');
});

it('queues again a write event restored from a payload that had no operation', function (): void {
    $heard = null;

    Event::listen(RoleAssigned::class, function (RoleAssigned $event) use (&$heard): void {
        $heard = $event;
    });

    $this->warden->assign('editor')->to($this->user);

    $restored = unserialize(payloadWithout($heard, 'operation'));
    $requeued = unserialize(serialize($restored));

    expect(array_keys($restored->__serialize()))->toBe(['authority', 'roles', 'scope', 'restrictedTo', 'actor', 'assignments'])
        ->and($restored->__serialize()['roles']->sole())->toBe($restored->roles->sole())
        ->and($requeued->operation ?? 'none')->toBe('none')
        ->and($requeued->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and($requeued->assignments[0]->role)->toBe($requeued->roles->sole());
});

it('reads the top-level models and an eloquent collection of a queued write again when the job runs', function (): void {
    Role::query()->create(['name' => 'editor']);
    $this->user->load('roles');

    $payload = serialize(new RoleAssigned($this->user, Role::query()->get(), null));

    DB::table('users')->update(['name' => 'Joseph Maria']);
    DB::table('roles')->update(['name' => 'publisher']);
    $queued = unserialize($payload);

    expect($queued->authority->getAttribute('name'))->toBe('Joseph Maria')
        ->and($queued->authority->relationLoaded('roles'))->toBeTrue()
        ->and($queued->roles)->toBeInstanceOf(EloquentCollection::class)
        ->and($queued->roles->sole()->getAttribute('name'))->toBe('publisher');
});
