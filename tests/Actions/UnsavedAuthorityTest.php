<?php

declare(strict_types=1);

use ElPandaPe\Warden\Actions\RetractsRoles;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\PermissionCreated;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\KeylessAccount;
use ElPandaPe\Warden\Tests\Fixtures\StringKeyUser;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('does not let an unsaved authority grant everyone', function (): void {
    $refusal = rescue(
        fn () => $this->warden->allow(new User(['name' => 'Ghost']))->to('delete-site'),
        fn (Throwable $exception): Throwable => $exception,
        report: false,
    );

    expect(Gate::forUser($this->user)->allows('delete-site'))->toBeFalse()
        ->and($refusal)->toBeInstanceOf(ConfigurationException::class);
});

it('refuses an unsaved authority on allow before any event or row', function (): void {
    config()->set('warden.cancellable_events', true);
    Event::fake([GrantingPermission::class, PermissionCreated::class]);

    expect(fn () => $this->warden->allow(new User(['name' => 'Ghost']))->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Permission::query()->count())->toBe(0)
        ->and(Grant::query()->count())->toBe(0);

    Event::assertNotDispatched(GrantingPermission::class);
    Event::assertNotDispatched(PermissionCreated::class);
});

it('refuses an unsaved authority on forbid, writing nothing', function (): void {
    expect(fn () => $this->warden->forbid(new User(['name' => 'Ghost']))->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Permission::query()->count())->toBe(0)
        ->and(Grant::query()->count())->toBe(0);
});

it('refuses an unsaved authority on sync, writing nothing', function (): void {
    $ghost = new User(['name' => 'Ghost']);

    expect(fn () => $this->warden->sync($ghost)->permissions(['delete-site']))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->sync($ghost)->forbiddenPermissions(['delete-site']))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->sync($ghost)->roles(['editor']))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Permission::query()->count())->toBe(0)
        ->and(Grant::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(AssignedRole::query()->count())->toBe(0);
});

it('refuses an unsaved authority on assign, writing nothing', function (): void {
    expect(fn () => $this->warden->assign('editor')->to(new User(['name' => 'Ghost'])))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Role::query()->count())->toBe(0)
        ->and(AssignedRole::query()->count())->toBe(0);
});

it('assigns to none of the authorities when one of them is unsaved', function (): void {
    expect(fn () => $this->warden->assign('editor')->to([$this->user, new User(['name' => 'Ghost'])]))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(AssignedRole::query()->count())->toBe(0)
        ->and($this->user->isA('editor'))->toBeFalse();
});

it('refuses a keyless authority on disallow and unforbid, removing nothing', function (): void {
    $this->warden->allow($this->user)->to('delete-site');
    $this->warden->forbid($this->user)->to('publish');
    $ghost = new User(['name' => 'Ghost']);

    expect(fn () => $this->warden->disallow($ghost)->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->unforbid($ghost)->to('publish'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Grant::query()->count())->toBe(2);
});

it('refuses a keyless authority on retract, removing nothing', function (): void {
    $this->warden->assign('editor')->to($this->user);

    expect(fn () => $this->warden->retract('editor')->from(new User(['name' => 'Ghost'])))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(AssignedRole::query()->count())->toBe(1);
});

it('refuses to grant, forbid, sync or assign a deleted model', function (): void {
    $this->user->delete();

    expect(fn () => $this->warden->allow($this->user)->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->forbid($this->user)->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->sync($this->user)->permissions(['delete-site']))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->assign('editor')->to($this->user))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Permission::query()->count())->toBe(0)
        ->and(Grant::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(AssignedRole::query()->count())->toBe(0);
});

it('still disallows and unforbids a deleted model that keeps its key', function (): void {
    $this->warden->allow($this->user)->to('delete-site');
    $this->warden->forbid($this->user)->to('publish');

    $this->user->delete();

    $this->warden->disallow($this->user)->to('delete-site');
    $this->warden->unforbid($this->user)->to('publish');

    expect(Grant::query()->count())->toBe(0);
});

it('retracts a role from a model inside its own deleted hook', function (): void {
    $this->warden->assign('editor')->to($this->user);

    User::deleted(fn (User $user): RetractsRoles => app(Warden::class)->retract('editor')->from($user));

    $this->user->delete();

    expect(AssignedRole::query()->count())->toBe(0);
});

it('refuses an authority whose key is null or an empty string', function (): void {
    $keyless = KeylessAccount::query()->create(['name' => 'Acme']);
    $blank = (new StringKeyUser)->newFromBuilder(['id' => '', 'name' => 'Blank']);

    expect(fn () => $this->warden->allow($keyless)->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->disallow($keyless)->to('delete-site'))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->assign('editor')->to($blank))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(fn () => $this->warden->retract('editor')->from($blank))
        ->toThrow(ConfigurationException::class, 'The authority must be a saved model with a usable key.')
        ->and(Grant::query()->count())->toBe(0)
        ->and(AssignedRole::query()->count())->toBe(0);
});
