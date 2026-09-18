<?php

declare(strict_types=1);

use ElPandaPe\Warden\Actions\AssignsRoles;
use ElPandaPe\Warden\Contracts\ActorResolver;
use ElPandaPe\Warden\Events\AssigningRole;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\CountingActorResolver;
use ElPandaPe\Warden\Tests\Fixtures\EventDoors;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();
    withForeignKeys();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ana']);
    $this->resolver = new CountingActorResolver;
});

it('builds a pre-event only when a listener could veto it', function (bool $silent, bool $enabled, bool $cancellable, int $builds): void {
    config()->set('warden.events_enabled', $enabled);
    config()->set('warden.cancellable_events', $cancellable);
    $built = 0;

    $permits = new EventDoors($silent)->permits(function () use (&$built): AssigningRole {
        $built++;

        return new AssigningRole(['editor'], [], null);
    });

    expect($permits)->toBeTrue()
        ->and($built)->toBe($builds);
})->with([
    'cancellable events off' => [false, true, false, 0],
    'events off' => [false, false, true, 0],
    'a silenced write' => [true, true, true, 0],
    'every door open' => [false, true, true, 1],
]);

it('builds a post-event only when it goes out', function (bool $silent, bool $enabled, int $builds): void {
    config()->set('warden.events_enabled', $enabled);
    $built = 0;

    new EventDoors($silent)->announce(function () use (&$built): RoleCreated {
        $built++;

        return new RoleCreated(new Role);
    });

    expect($built)->toBe($builds);
})->with([
    'a silenced write' => [true, true, 0],
    'events off' => [false, false, 0],
    'an open door' => [false, true, 1],
]);

it('asks the actor resolver nothing while events are off', function (Closure $write): void {
    $this->warden->allow($this->user)->to('publish');
    $this->warden->assign('editor')->to($this->user);
    config()->set('warden.events_enabled', false);
    app()->instance(ActorResolver::class, $this->resolver);

    $write($this->warden, $this->user);

    expect($this->resolver->calls)->toBe(0);
})->with([
    'a grant' => [fn (Warden $warden, User $user): mixed => $warden->allow($user)->to('view')],
    'an assignment' => [fn (Warden $warden, User $user): mixed => $warden->assign('auditor')->to($user)],
    'a revoke' => [fn (Warden $warden, User $user): mixed => $warden->disallow($user)->to('publish')],
    'a retract' => [fn (Warden $warden, User $user): mixed => $warden->retract('editor')->from($user)],
    'a narrowed grant' => [fn (Warden $warden, User $user): mixed => $warden->allow($user)->to('view', Account::class)->where('name', 'Acme')],
    'a roles sync' => [fn (Warden $warden, User $user): mixed => $warden->sync($user)->roles(['editor', 'auditor'])],
    'a permissions sync' => [fn (Warden $warden, User $user): mixed => $warden->sync($user)->permissions(['view'])],
    'a role create' => [fn (): mixed => Role::query()->create(['name' => 'auditor'])],
    'a role delete' => [fn (): mixed => Role::query()->where('name', 'editor')->sole()->delete()],
    'a permission delete' => [fn (): mixed => Permission::query()->where('name', 'publish')->sole()->delete()],
]);

it('asks the actor resolver once for a roles sync that adds an assignment', function (): void {
    Role::query()->create(['name' => 'editor']);
    app()->instance(ActorResolver::class, $this->resolver);

    $this->warden->sync($this->user)->roles(['editor']);

    expect($this->resolver->calls)->toBe(1)
        ->and($this->user->isAn('editor'))->toBeTrue();
});

it('asks the actor resolver nothing for an assignment a sync silences', function (): void {
    Role::query()->create(['name' => 'editor']);
    app()->instance(ActorResolver::class, $this->resolver);

    new AssignsRoles('editor', silentEvents: true)->to($this->user);

    expect($this->resolver->calls)->toBe(0)
        ->and($this->user->isAn('editor'))->toBeTrue();
});

it('asks the actor resolver once for a call that announces several events', function (Closure $arrange, Closure $write): void {
    $other = User::query()->create(['name' => 'Luis']);
    $arrange($this->warden, $this->user, $other);
    app()->instance(ActorResolver::class, $this->resolver);

    $write($this->warden, $this->user, $other);

    expect($this->resolver->calls)->toBe(1);
})->with([
    'a retract from two authorities' => [
        fn (Warden $warden, User $user, User $other): mixed => $warden->assign('editor')->to([$user, $other]),
        fn (Warden $warden, User $user, User $other): mixed => $warden->retract('editor')->from([$user, $other]),
    ],
    'a narrowing that revokes one rule and grants another' => [
        function (Warden $warden, User $user, User $other): void {
            $warden->allow($other)->to('view', Account::class)->where('name', 'Acme');
            $warden->allow($user)->to('view', Account::class);
        },
        fn (Warden $warden, User $user): mixed => $warden->allow($user)->to('view', Account::class)->where('name', 'Acme'),
    ],
]);

it('asks the actor resolver once per catalog delete and once per cascade', function (Closure $delete): void {
    $other = User::query()->create(['name' => 'Luis']);
    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow($other)->to('publish');
    $this->warden->assign('editor')->to([$this->user, $other]);
    app()->instance(ActorResolver::class, $this->resolver);

    $delete();

    expect($this->resolver->calls)->toBe(2);
})->with([
    'a permission' => [fn (): mixed => Permission::query()->where('name', 'publish')->sole()->delete()],
    'a role' => [fn (): mixed => Role::query()->where('name', 'editor')->sole()->delete()],
]);

it('asks the actor resolver only for the catalog event when no cascaded holder can be named', function (string $pivot, Closure $delete): void {
    $publish = Permission::query()->create(['name' => 'publish']);
    $editor = Role::query()->create(['name' => 'editor']);
    DB::table($pivot)->insert($pivot === 'grants'
        ? ['permission_id' => $publish->getKey(), 'entity_type' => 'nothing.maps.here', 'entity_id' => 1, 'forbidden' => false]
        : ['role_id' => $editor->getKey(), 'entity_type' => 'nothing.maps.here', 'entity_id' => 1]);
    Log::spy();
    app()->instance(ActorResolver::class, $this->resolver);

    $delete();

    expect($this->resolver->calls)->toBe(1);
})->with([
    'a permission' => ['grants', fn (): mixed => Permission::query()->where('name', 'publish')->sole()->delete()],
    'a role' => ['assigned_roles', fn (): mixed => Role::query()->where('name', 'editor')->sole()->delete()],
]);

it('asks the actor resolver nothing for a retract whose removals no one can name', function (): void {
    $editor = Role::query()->create(['name' => 'editor']);
    DB::table('assigned_roles')->insert([
        'role_id' => $editor->getKey(),
        'entity_type' => $this->user->getMorphClass(),
        'entity_id' => $this->user->getKey(),
        'restricted_to_type' => 'nothing.maps.here',
        'restricted_to_id' => 1,
    ]);
    Log::spy();
    app()->instance(ActorResolver::class, $this->resolver);

    $retract = $this->warden->retract('editor')->from($this->user);

    expect($retract->retractedCount())->toBe(1)
        ->and($this->resolver->calls)->toBe(0);
});
