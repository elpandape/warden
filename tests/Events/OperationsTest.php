<?php

declare(strict_types=1);

use ElPandaPe\Warden\Actions\AssignsRoles;
use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Actions\SyncsRolesAndPermissions;
use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Context;
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
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;
use function ElPandaPe\Warden\Tests\heardWardenEvents;
use function Illuminate\Events\queueable;

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

it('gives every event of a write outside Warden::operation() one operation of its own', function (Closure $arrange, Closure $write, array $expected): void {
    $arrange($this->warden, $this->user);
    $heard = heardWardenEvents();

    $write($this->warden, $this->user);

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe($expected)
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
})->with('writes');

it('gives two calls in a row an id each', function (): void {
    $heard = heardWardenEvents();

    $this->warden->allow($this->user)->to('publish')->to('review');

    $first = $heard[0]->operation;
    $second = $heard[3]->operation;

    expect(array_map(fn (object $event): ?string => $event->operation, [...$heard]))->toBe([$first, $first, $first, $second, $second, $second])
        ->and(Str::isUlid((string) $first))->toBeTrue()
        ->and(Str::isUlid((string) $second))->toBeTrue()
        ->and($first)->not->toBe($second);
});

it('keeps the identical repeat of a narrowing chain in one operation', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');
    $heard = heardWardenEvents();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([
        GrantingPermission::class, PermissionCreated::class, PermissionGranted::class,
        PermissionRevoked::class, PermissionDeleted::class,
    ])->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('keeps a chain narrowed twice in one operation', function (): void {
    $heard = heardWardenEvents();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme')->orWhere('name', 'Globex');

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([
        GrantingPermission::class, PermissionCreated::class, PermissionGranted::class,
        PermissionCreated::class, PermissionRevoked::class, PermissionGranted::class, PermissionDeleted::class,
        PermissionCreated::class, PermissionRevoked::class, PermissionGranted::class, PermissionDeleted::class,
    ])->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('resumes the operation of a to() that found its grant already there', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);
    $heard = heardWardenEvents();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([
        GrantingPermission::class, PermissionCreated::class, PermissionRevoked::class, PermissionGranted::class,
    ])->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('lets an open operation win over the chain it resumes', function (): void {
    $heard = heardWardenEvents();
    $chain = $this->warden->allow($this->user)->to('view', Account::class);

    $open = $this->warden->operation(function (string $open) use ($chain): string {
        $chain->where('name', 'Acme');

        return $open;
    });

    $granted = $heard[0]->operation;

    expect(array_map(fn (object $event): ?string => $event->operation, [...$heard]))->toBe([$granted, $granted, $granted, $open, $open, $open, $open])
        ->and(Str::isUlid((string) $granted))->toBeTrue()
        ->and($granted)->not->toBe($open);
});

it('gives each call of a sync chain its own operation, unless one is open', function (): void {
    $heard = heardWardenEvents();

    $this->warden->sync($this->user)->roles(['editor'])->permissions(['publish']);

    $roles = $heard[0]->operation;
    $permissions = $heard[2]->operation;

    $open = $this->warden->operation(function (string $open): string {
        $this->warden->sync($this->user)->roles(['auditor'])->permissions(['review']);

        return $open;
    });

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([
        RoleCreated::class, RolesSynced::class, PermissionCreated::class, PermissionsSynced::class,
        RoleCreated::class, RolesSynced::class, PermissionCreated::class, PermissionsSynced::class,
    ])->and(array_map(fn (object $event): ?string => $event->operation, [...$heard]))->toBe([
        $roles, $roles, $permissions, $permissions,
        $open, $open, $open, $open,
    ])->and(Str::isUlid((string) $roles))->toBeTrue()
        ->and(Str::isUlid((string) $permissions))->toBeTrue()
        ->and($roles)->not->toBe($permissions);
});

it('announces a catalog delete and every row its cascade removed under one id', function (Closure $arrange, Closure $delete, array $expected): void {
    $arrange($this->warden, [$this->user, User::query()->create(['name' => 'Ana'])]);
    $heard = heardWardenEvents();

    $delete();

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe($expected)
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
})->with([
    'a role two holders hold' => [
        fn (Warden $warden, array $holders): AssignsRoles => $warden->assign('editor')->to($holders),
        fn (): ?bool => Role::query()->where('name', 'editor')->sole()->delete(),
        [RoleDeleted::class, RoleRetracted::class, RoleRetracted::class],
    ],
    'a permission granted to two holders' => [
        function (Warden $warden, array $holders): void {
            foreach ($holders as $holder) {
                $warden->allow($holder)->to('publish');
            }
        },
        fn (): ?bool => Permission::query()->where('name', 'publish')->sole()->delete(),
        [PermissionDeleted::class, PermissionRevoked::class, PermissionRevoked::class],
    ],
]);

it('gives the soft deletion of a role an id', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);
    $editor = SoftDeletingRole::query()->create(['name' => 'editor']);
    $heard = heardWardenEvents();

    $editor->delete();

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([RoleDeleted::class])
        ->and(Str::isUlid((string) $heard[0]->operation))->toBeTrue();
});

it('gives a catalog row created outside any call and its later update an id each', function (): void {
    $heard = heardWardenEvents();

    $editor = Role::query()->create(['name' => 'editor']);
    $editor->update(['title' => 'Chief editor']);

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([RoleCreated::class, RoleUpdated::class])
        ->and(Str::isUlid((string) $heard[0]->operation))->toBeTrue()
        ->and(Str::isUlid((string) $heard[1]->operation))->toBeTrue()
        ->and($heard[0]->operation)->not->toBe($heard[1]->operation);
});

it('lets a listener that writes join the operation it hears', function (): void {
    $auditor = User::query()->create(['name' => 'Ana']);
    $mirrored = false;
    Event::listen(PermissionGranted::class, function () use ($auditor, &$mirrored): void {
        if ($mirrored) {
            return;
        }

        $mirrored = true;
        $this->warden->allow($auditor)->to('audit');
    });
    $heard = heardWardenEvents();

    $this->warden->allow($this->user)->to('publish');

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([
        GrantingPermission::class, PermissionCreated::class,
        GrantingPermission::class, PermissionCreated::class, PermissionGranted::class,
        PermissionGranted::class,
    ])->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('announces what the deprecated markCascade settles under one id', function (): void {
    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow(User::query()->create(['name' => 'Ana']))->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole();
    $invalidations = app(CacheInvalidations::class);
    $invalidations->prepareCascade($publish);
    $publish->deleteQuietly();
    $heard = heardWardenEvents();

    $invalidations->markCascade($publish);

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([PermissionRevoked::class, PermissionRevoked::class])
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('announces a cascade settled by hand under one id', function (): void {
    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow(User::query()->create(['name' => 'Ana']))->to('publish');
    $publish = Permission::query()->where('name', 'publish')->sole();
    $invalidations = app(CacheInvalidations::class);
    $heard = heardWardenEvents();

    $invalidations->prepareCascade($publish);
    $publish->deleteQuietly();
    $invalidations->settleCascade($publish);
    $invalidations->announceCascade($publish);

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([PermissionRevoked::class, PermissionRevoked::class])
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('runs warden:clean as one operation', function (): void {
    Permission::query()->create(['name' => 'orphan-one']);
    Permission::query()->create(['name' => 'orphan-two']);
    $heard = heardWardenEvents();

    $this->artisan('warden:clean')->assertSuccessful();

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe([PermissionDeleted::class, PermissionDeleted::class])
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
});

it('gives the calls the shared dataset leaves out one id each', function (Closure $write, array $expected): void {
    $heard = heardWardenEvents();

    $write($this->warden, $this->user);

    $ids = array_values(array_unique(array_map(fn (object $event): ?string => $event->operation, [...$heard])));

    expect(array_map(fn (object $event): string => $event::class, [...$heard]))->toBe($expected)
        ->and($ids)->toHaveCount(1)
        ->and(Str::isUlid((string) $ids[0]))->toBeTrue();
})->with([
    'an owned-only grant' => [
        fn (Warden $warden, User $user): GrantsPermissions => $warden->allow($user)->toOwn(Account::class, 'edit'),
        [GrantingPermission::class, PermissionCreated::class, PermissionGranted::class],
    ],
    'a forbidden-permissions sync' => [
        fn (Warden $warden, User $user): SyncsRolesAndPermissions => $warden->sync($user)->forbiddenPermissions(['publish']),
        [PermissionCreated::class, PermissionsSynced::class],
    ],
]);

it('lets a listener queued on the sync driver join the operation it hears', function (): void {
    $seen = [];
    Event::listen(queueable(function (PermissionGranted $event): void {
        app(Warden::class)->assign('auditor')->to(User::query()->sole());
    }));
    Event::listen([PermissionGranted::class, RoleAssigned::class], function (object $event) use (&$seen): void {
        $seen[] = $event->operation;
    });

    $this->warden->allow($this->user)->to('publish');

    expect(config('queue.default'))->toBe('sync')
        ->and($seen)->toHaveCount(2)
        ->and(array_unique($seen))->toHaveCount(1);
});
