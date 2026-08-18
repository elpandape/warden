<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->admin = User::query()->create(['name' => 'Admin']);
    $this->editor = User::query()->create(['name' => 'Editor']);
    $this->both = User::query()->create(['name' => 'Both']);
    $this->none = User::query()->create(['name' => 'None']);

    $this->warden->assign('admin')->to([$this->admin, $this->both]);
    $this->warden->assign('editor')->to([$this->editor, $this->both]);
});

it('filters authorities having any of the roles', function (): void {
    expect(User::query()->whereIs('admin')->pluck('name')->sort()->values()->all())->toBe(['Admin', 'Both'])
        ->and(User::query()->whereIs('admin', 'editor')->count())->toBe(3);
});

it('filters authorities having all of the roles', function (): void {
    expect(User::query()->whereIsAll('admin', 'editor')->pluck('name')->all())->toBe(['Both'])
        ->and(User::query()->whereIsAll('admin', 'admin')->count())->toBe(2);
});

it('filters authorities lacking the roles', function (): void {
    expect(User::query()->whereIsNot('admin')->pluck('name')->sort()->values()->all())->toBe(['Editor', 'None'])
        ->and(User::query()->whereIsNot('admin', 'editor')->pluck('name')->all())->toBe(['None']);
});

it('hides scoped assignments under strict null behavior', function (): void {
    $this->warden->tenant()->to(1);
    $scoped = User::query()->create(['name' => 'Scoped']);
    $this->warden->assign('tenant-admin')->to($scoped);
    $this->warden->tenant()->remove();

    config()->set('warden.scope.null_behavior', 'strict');

    expect($scoped->isA('tenant-admin'))->toBeFalse()
        ->and($this->admin->isA('admin'))->toBeTrue();
});
