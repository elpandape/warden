<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;

beforeEach(function (): void {
    migrateWardenTables();
    withForeignKeys();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
});

it('takes the assignments of a deleted role with it', function (): void {
    $this->warden->assign('editor')->to([$this->user, User::query()->create(['name' => 'Grace'])]);

    Role::query()->where('name', 'editor')->sole()->delete();

    expect(AssignedRole::query()->count())->toBe(0);
});

it('takes the grants of a deleted permission with it', function (): void {
    $this->warden->allow($this->user)->to('publish');

    Permission::query()->where('name', 'publish')->sole()->delete();

    expect(Grant::query()->count())->toBe(0);
});
