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
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();
    withForeignKeys();
    config()->set('warden.cancellable_events', true);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

dataset('writes', [
    'allow' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->allow('editor')->to('publish');
        },
        [GrantingPermission::class, PermissionCreated::class, RoleCreated::class, PermissionGranted::class],
    ],
    'forbid' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->forbid($user)->to('publish');
        },
        [ForbiddingPermission::class, PermissionCreated::class, PermissionForbidden::class],
    ],
    'assign' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->assign('editor')->to($user);
        },
        [AssigningRole::class, RoleCreated::class, RoleAssigned::class],
    ],
    'retract' => [
        function (Warden $warden, User $user): void {
            $warden->assign('editor')->to($user);
        },
        function (Warden $warden, User $user): void {
            $warden->retract('editor')->from($user);
        },
        [RetractingRole::class, RoleRetracted::class],
    ],
    'disallow' => [
        function (Warden $warden, User $user): void {
            $warden->allow($user)->to('publish');
        },
        function (Warden $warden, User $user): void {
            $warden->disallow($user)->to('publish');
        },
        [RevokingPermission::class, PermissionRevoked::class],
    ],
    'unforbid' => [
        function (Warden $warden, User $user): void {
            $warden->forbid($user)->to('publish');
        },
        function (Warden $warden, User $user): void {
            $warden->unforbid($user)->to('publish');
        },
        [UnforbiddingPermission::class, PermissionUnforbidden::class],
    ],
    'sync roles' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->sync($user)->roles(['editor']);
        },
        [RoleCreated::class, RolesSynced::class],
    ],
    'sync permissions' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->sync($user)->permissions(['publish']);
        },
        [PermissionCreated::class, PermissionsSynced::class],
    ],
    'a narrowed grant' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->allow($user)->to('view', Account::class)->where('name', 'Acme');
        },
        [
            GrantingPermission::class, PermissionCreated::class, PermissionGranted::class,
            PermissionCreated::class, PermissionRevoked::class, PermissionGranted::class, PermissionDeleted::class,
        ],
    ],
    'a role delete and its cascade' => [
        function (Warden $warden, User $user): void {
            $warden->assign('editor')->to($user);
        },
        function (Warden $warden, User $user): void {
            Role::query()->where('name', 'editor')->sole()->delete();
        },
        [RoleDeleted::class, RoleRetracted::class],
    ],
    'a permission delete and its cascade' => [
        function (Warden $warden, User $user): void {
            $warden->allow($user)->to('publish');
        },
        function (Warden $warden, User $user): void {
            Permission::query()->where('name', 'publish')->sole()->delete();
        },
        [PermissionDeleted::class, PermissionRevoked::class],
    ],
    'a narrowed prohibition' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            $warden->forbid($user)->to('view', Account::class)->where('name', 'Acme');
        },
        [
            ForbiddingPermission::class, PermissionCreated::class, PermissionForbidden::class,
            PermissionCreated::class, PermissionUnforbidden::class, PermissionForbidden::class, PermissionDeleted::class,
        ],
    ],
    'a permission delete that lifts a prohibition' => [
        function (Warden $warden, User $user): void {
            $warden->forbid($user)->to('publish');
        },
        function (Warden $warden, User $user): void {
            Permission::query()->where('name', 'publish')->sole()->delete();
        },
        [PermissionDeleted::class, PermissionUnforbidden::class],
    ],
    'a role create' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            Role::query()->create(['name' => 'editor']);
        },
        [RoleCreated::class],
    ],
    'a role update' => [
        function (Warden $warden, User $user): void {
            Role::query()->create(['name' => 'editor']);
        },
        function (Warden $warden, User $user): void {
            Role::query()->where('name', 'editor')->sole()->update(['title' => 'Chief editor']);
        },
        [RoleUpdated::class],
    ],
    'a permission create' => [
        function (Warden $warden, User $user): void {},
        function (Warden $warden, User $user): void {
            Permission::query()->create(['name' => 'publish']);
        },
        [PermissionCreated::class],
    ],
    'a permission update' => [
        function (Warden $warden, User $user): void {
            Permission::query()->create(['name' => 'publish']);
        },
        function (Warden $warden, User $user): void {
            Permission::query()->where('name', 'publish')->sole()->update(['title' => 'Publish articles']);
        },
        [PermissionUpdated::class],
    ],
]);

it('stamps every event of a write inside Warden::operation() with its id', function (Closure $arrange, Closure $write, array $expected): void {
    $arrange($this->warden, $this->user);

    $heard = [];
    Event::listen('ElPandaPe\Warden\Events\*', function (string $name, array $payload) use (&$heard): void {
        $heard[] = $payload[0];
    });

    $id = $this->warden->operation(function (string $id) use ($write): string {
        $write($this->warden, $this->user);

        return $id;
    });

    expect(array_map(fn (object $event): string => $event::class, $heard))->toBe($expected)
        ->and(array_map(fn (object $event): ?string => $event->operation, $heard))->toBe(array_fill(0, count($expected), $id));
})->with('writes');

it('leaves every event of a write outside Warden::operation() without an operation', function (Closure $arrange, Closure $write, array $expected): void {
    $arrange($this->warden, $this->user);

    $heard = [];
    Event::listen('ElPandaPe\Warden\Events\*', function (string $name, array $payload) use (&$heard): void {
        $heard[] = $payload[0];
    });

    $write($this->warden, $this->user);

    expect(array_map(fn (object $event): string => $event::class, $heard))->toBe($expected)
        ->and(array_map(fn (object $event): ?string => $event->operation, $heard))->toBe(array_fill(0, count($expected), null));
})->with('writes');
