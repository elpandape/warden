<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Database\Permission;
use ElPandaPe\Bouncer\Database\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('grants a simple permission, creating it on the fly', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    $permission = Permission::query()->where('name', 'edit-site')->sole();
    $grant = Grant::query()->sole();

    expect($grant->getAttribute('permission_id'))->toBe($permission->getKey())
        ->and($grant->getAttribute('forbidden'))->toBeFalse()
        ->and($grant->getAttribute('entity_id'))->toBe($this->user->getKey());
});

it('is idempotent when granting twice', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Grant::query()->count())->toBe(1)
        ->and(Permission::query()->count())->toBe(1);
});

it('grants to a role by name, creating the role', function (): void {
    $this->bouncer->allow('admin')->to('ban-users');

    $role = Role::query()->where('name', 'admin')->sole();

    expect(Grant::query()->where('entity_type', 'bouncer.role')->where('entity_id', $role->getKey())->count())->toBe(1);
});

it('grants class-wide and instance permissions', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->bouncer->allow($this->user)
        ->to('view', Account::class)
        ->to('edit', $account);

    expect(Permission::query()->where('name', 'view')->whereNull('entity_id')->sole()->entity_type)
        ->toBe($account->getMorphClass())
        ->and(Permission::query()->where('name', 'edit')->sole()->entity_id)->toBe($account->getKey());
});

it('grants wildcards through everything and toManage', function (): void {
    $this->bouncer->allow($this->user)->everything();
    $this->bouncer->allow($this->user)->toManage(Account::class);

    expect(Permission::query()->where('name', '*')->where('entity_type', '*')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', '*')->where('entity_type', (new Account)->getMorphClass())->exists())->toBeTrue();
});

it('grants ownership-scoped permissions', function (): void {
    $this->bouncer->allow($this->user)->toOwn(Account::class, ['view', 'update']);
    $this->bouncer->allow($this->user)->toOwnEverything();

    expect(Permission::query()->where('only_owned', true)->where('name', 'view')->exists())->toBeTrue()
        ->and(Permission::query()->where('only_owned', true)->where('name', '*')->where('entity_type', '*')->exists())->toBeTrue()
        ->and(Grant::query()->count())->toBe(3);
});

it('accepts permission models and arrays', function (): void {
    $permission = Permission::query()->create(['name' => 'preexisting']);

    $this->bouncer->allow($this->user)->to([$permission])->to(['a', 'b']);

    expect(Grant::query()->count())->toBe(3)
        ->and(Permission::query()->count())->toBe(3);
});

it('grants to everyone with a null entity', function (): void {
    $this->bouncer->allowEveryone()->to('browse');

    $grant = Grant::query()->sole();

    expect($grant->getAttribute('entity_type'))->toBeNull()
        ->and($grant->getAttribute('entity_id'))->toBeNull();
});

it('forbids with the forbidden flag and keeps both rows apart', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->forbid($this->user)->to('edit-site');
    $this->bouncer->forbidEveryone()->to('hack');

    expect(Grant::query()->where('forbidden', true)->count())->toBe(2)
        ->and(Grant::query()->where('forbidden', false)->count())->toBe(1)
        ->and(Permission::query()->count())->toBe(2);
});

it('rejects unsaved entity instances', function (): void {
    $this->bouncer->allow($this->user)->to('edit', new Account);
})->throws(InvalidArgumentException::class, 'does not exist');

it('rejects entity strings that are not model classes', function (): void {
    $this->bouncer->allow($this->user)->to('edit', 'Not\A\Model');
})->throws(InvalidArgumentException::class, 'must be a model class');

it('rejects invalid permission inputs', function (): void {
    $this->bouncer->allow($this->user)->to([123]);
})->throws(InvalidArgumentException::class, 'names or permission models');
