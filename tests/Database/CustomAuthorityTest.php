<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();
});

it('grants roles and permissions to authorities that are not users', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $account->roles()->attach(Role::query()->create(['name' => 'tenant-admin']));
    $account->permissions()->attach(Permission::query()->create(['name' => 'access-billing']), ['forbidden' => false]);

    expect($account->isA('tenant-admin'))->toBeTrue()
        ->and($account->permissions()->pluck('name')->all())->toBe(['access-billing']);
});

it('keeps authorities of different types apart', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);
    $user = ElPandaPe\Bouncer\Tests\Fixtures\User::query()->create(['name' => 'Joseph']);

    $account->roles()->attach(Role::query()->create(['name' => 'tenant-admin']));

    expect($account->isA('tenant-admin'))->toBeTrue()
        ->and($user->isA('tenant-admin'))->toBeFalse();
});
