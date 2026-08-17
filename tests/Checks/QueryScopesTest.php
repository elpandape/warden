<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->admin = User::query()->create(['name' => 'Admin']);
    $this->editor = User::query()->create(['name' => 'Editor']);
    $this->both = User::query()->create(['name' => 'Both']);
    $this->none = User::query()->create(['name' => 'None']);

    $this->bouncer->assign('admin')->to([$this->admin, $this->both]);
    $this->bouncer->assign('editor')->to([$this->editor, $this->both]);
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
    $this->bouncer->tenant()->to(1);
    $scoped = User::query()->create(['name' => 'Scoped']);
    $this->bouncer->assign('tenant-admin')->to($scoped);
    $this->bouncer->tenant()->remove();

    config()->set('bouncer.scope.null_behavior', 'strict');

    expect($scoped->isA('tenant-admin'))->toBeFalse()
        ->and($this->admin->isA('admin'))->toBeTrue();
});
