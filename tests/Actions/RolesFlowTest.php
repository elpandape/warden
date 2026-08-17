<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Models\AssignedRole;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\Plain;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('assigns roles by name, creating them on the fly', function (): void {
    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->assign('admin')->to($this->user);

    expect(AssignedRole::query()->count())->toBe(1)
        ->and($this->user->isA('admin'))->toBeTrue();
});

it('assigns multiple roles to multiple authorities', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $account = Account::query()->create(['name' => 'Acme']);

    $this->bouncer->assign(['admin', 'editor'])->to([$this->user, $other, $account]);

    expect(AssignedRole::query()->count())->toBe(6)
        ->and($account->isAll('admin', 'editor'))->toBeTrue();
});

it('assigns role models directly', function (): void {
    $role = Role::query()->create(['name' => 'admin']);

    $this->bouncer->assign($role)->to($this->user);

    expect($this->user->isA('admin'))->toBeTrue();
});

it('retracts roles by name and by model', function (): void {
    $this->bouncer->assign(['admin', 'editor'])->to($this->user);

    $this->bouncer->retract('admin')->from($this->user);
    $this->bouncer->retract(Role::query()->where('name', 'editor')->sole())->from($this->user);

    expect(AssignedRole::query()->count())->toBe(0);
});

it('rejects invalid role and authority inputs', function (): void {
    expect(fn () => $this->bouncer->assign([42])->to($this->user))
        ->toThrow(InvalidArgumentException::class, 'names or role models')
        ->and(fn () => $this->bouncer->retract([42])->from($this->user))
        ->toThrow(InvalidArgumentException::class, 'names or role models')
        ->and(fn () => $this->bouncer->assign('admin')->to(['not-a-model']))
        ->toThrow(InvalidArgumentException::class, 'model instances')
        ->and(fn () => $this->bouncer->retract('admin')->from(['not-a-model']))
        ->toThrow(InvalidArgumentException::class, 'model instances');
});

it('syncs roles adding the missing and removing the extra', function (): void {
    $this->bouncer->assign(['admin', 'editor'])->to($this->user);

    $this->bouncer->sync($this->user)->roles(['editor', 'writer']);

    expect($this->user->isA('admin'))->toBeFalse()
        ->and($this->user->isAll('editor', 'writer'))->toBeTrue()
        ->and(AssignedRole::query()->count())->toBe(2);
});

it('syncs roles to empty', function (): void {
    $this->bouncer->assign('admin')->to($this->user);

    $this->bouncer->sync($this->user)->roles([]);

    expect(AssignedRole::query()->count())->toBe(0);
});

it('syncs granted and forbidden permissions independently', function (): void {
    $this->bouncer->allow($this->user)->to(['a', 'b']);
    $this->bouncer->forbid($this->user)->to('x');

    $this->bouncer->sync($this->user)->permissions(['b', 'c']);
    $this->bouncer->sync($this->user)->forbiddenPermissions([Permission::query()->where('name', 'x')->sole()]);

    $granted = Grant::query()->where('forbidden', false)->pluck('permission_id');

    expect(Permission::query()->whereIn('id', $granted)->pluck('name')->sort()->values()->all())->toBe(['b', 'c'])
        ->and(Grant::query()->where('forbidden', true)->count())->toBe(1);
});

it('syncs for a role authority given by name', function (): void {
    $this->bouncer->sync('moderator')->permissions(['review']);

    $role = Role::query()->where('name', 'moderator')->sole();

    expect(Grant::query()->where('entity_type', 'bouncer.role')->where('entity_id', $role->getKey())->count())->toBe(1);
});

it('checks roles through the fluent is() entry', function (): void {
    $this->bouncer->assign(['admin', 'editor'])->to($this->user);

    expect($this->bouncer->is($this->user)->a('admin'))->toBeTrue()
        ->and($this->bouncer->is($this->user)->an('editor'))->toBeTrue()
        ->and($this->bouncer->is($this->user)->notA('ghost'))->toBeTrue()
        ->and($this->bouncer->is($this->user)->notAn('admin'))->toBeFalse()
        ->and($this->bouncer->is($this->user)->all('admin', 'editor'))->toBeTrue();
});

it('refuses role checks on models without the concern', function (): void {
    $plain = Plain::query()->create(['name' => 'nobody']);

    $this->bouncer->is($plain);
})->throws(InvalidArgumentException::class, 'HasRolesAndPermissions');

it('builds model instances from the container entry points', function (): void {
    expect($this->bouncer->role(['name' => 'r']))->toBeInstanceOf(Role::class)
        ->and($this->bouncer->permission(['name' => 'p']))->toBeInstanceOf(Permission::class);
});

it('is exposed through the facade', function (): void {
    ElPandaPe\Bouncer\Facades\Bouncer::allow($this->user)->to('via-facade');

    expect(Grant::query()->count())->toBe(1);
});

it('syncs permissions to empty and unforbids everyone', function (): void {
    $this->bouncer->allow($this->user)->to('a');
    $this->bouncer->sync($this->user)->permissions([]);

    $this->bouncer->forbidEveryone()->to('hack');
    $this->bouncer->unforbidEveryone()->to('hack');

    expect(Grant::query()->count())->toBe(0);
});

it('syncs roles given as models', function (): void {
    $role = Role::query()->create(['name' => 'admin']);

    $this->bouncer->sync($this->user)->roles([$role]);

    expect($this->user->isA('admin'))->toBeTrue();
});

it('rejects role and permission models without usable keys', function (): void {
    expect(fn () => $this->bouncer->assign(new ElPandaPe\Bouncer\Tests\Fixtures\KeylessRole)->to($this->user))
        ->toThrow(InvalidArgumentException::class, 'int or string key')
        ->and(fn () => $this->bouncer->sync($this->user)->roles([new ElPandaPe\Bouncer\Tests\Fixtures\KeylessRole]))
        ->toThrow(InvalidArgumentException::class, 'int or string key')
        ->and(fn () => $this->bouncer->allow($this->user)->to([new ElPandaPe\Bouncer\Tests\Fixtures\KeylessPermission]))
        ->toThrow(InvalidArgumentException::class, 'int or string key');
});
