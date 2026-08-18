<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
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
    $user = ElPandaPe\Warden\Tests\Fixtures\User::query()->create(['name' => 'Joseph']);

    $account->roles()->attach(Role::query()->create(['name' => 'tenant-admin']));

    expect($account->isA('tenant-admin'))->toBeTrue()
        ->and($user->isA('tenant-admin'))->toBeFalse();
});
