<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Models\AssignedRole;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('relates authorities to roles through the assigned roles pivot', function (): void {
    $role = Role::query()->create(['name' => 'admin']);

    $this->user->roles()->attach($role);

    expect($this->user->roles()->pluck('name')->all())->toBe(['admin'])
        ->and($this->user->roles()->first()?->pivot)->toBeInstanceOf(AssignedRole::class);
});

it('relates authorities to permissions through the grants pivot', function (): void {
    $permission = Permission::query()->create(['name' => 'edit-site']);

    $this->user->permissions()->attach($permission, ['forbidden' => false]);

    $pivot = $this->user->permissions()->first()?->pivot;

    expect($this->user->permissions()->pluck('name')->all())->toBe(['edit-site'])
        ->and($pivot)->toBeInstanceOf(Grant::class)
        ->and($pivot?->getAttribute('forbidden'))->toBeFalse();
});

it('relates roles to permissions through the same grants pivot', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $permission = Permission::query()->create(['name' => 'edit-site']);

    $role->permissions()->attach($permission, ['forbidden' => false]);

    expect($role->permissions()->pluck('name')->all())->toBe(['edit-site'])
        ->and($permission->roles()->pluck('name')->all())->toBe(['editor']);
});

it('resolves pivot tables from the context when used standalone', function (): void {
    expect((new AssignedRole)->getTable())->toBe('assigned_roles')
        ->and((new Grant)->getTable())->toBe('grants');
});

it('does not use pivot timestamps by default', function (): void {
    expect((new AssignedRole)->usesTimestamps())->toBeFalse()
        ->and((new Grant)->usesTimestamps())->toBeFalse();

    config()->set('bouncer.pivot_timestamps', true);

    expect((new AssignedRole)->usesTimestamps())->toBeTrue()
        ->and((new Grant)->usesTimestamps())->toBeTrue();
});

it('declares no cast on entity ids so uuid and ulid keys survive', function (): void {
    // A hardcoded int cast here is the original package's #626 bug.
    expect((new Permission)->getCasts())->not->toHaveKey('entity_id');
});

it('selects pivot timestamps when the opt-in is enabled', function (): void {
    $user = User::query()->create(['name' => 'Joseph']);

    expect($user->roles()->getPivotColumns())->not->toContain('created_at');

    config()->set('bouncer.pivot_timestamps', true);

    expect($user->roles()->getPivotColumns())->toContain('created_at', 'updated_at')
        ->and($user->permissions()->getPivotColumns())->toContain('created_at');
});
