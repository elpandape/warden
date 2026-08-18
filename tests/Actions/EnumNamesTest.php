<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\PermissionName;
use ElPandaPe\Warden\Tests\Fixtures\RoleName;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('accepts backed enums across the write api', function (): void {
    $this->warden->allow($this->user)->to(PermissionName::EditSite);
    $this->warden->forbid($this->user)->to(PermissionName::Publish);
    $this->warden->assign(RoleName::Admin)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and($this->user->isAn('admin'))->toBeTrue();

    $this->warden->unforbid($this->user)->to(PermissionName::Publish);
    $this->warden->disallow($this->user)->to(PermissionName::EditSite);
    $this->warden->retract(RoleName::Admin)->from($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and($this->user->isAn('admin'))->toBeFalse();
});

it('accepts backed enums across the check api', function (): void {
    $this->warden->allow($this->user)->to(PermissionName::EditSite);
    $this->warden->assign(RoleName::Editor)->to($this->user);
    $this->actingAs($this->user);

    expect($this->warden->can(PermissionName::EditSite))->toBeTrue()
        ->and($this->warden->cannot(PermissionName::Publish))->toBeTrue()
        ->and($this->warden->canAny([PermissionName::Publish, 'edit-site']))->toBeTrue()
        ->and($this->warden->authorize(PermissionName::EditSite))->not->toBeNull()
        ->and($this->warden->is($this->user)->a(RoleName::Editor))->toBeTrue()
        ->and($this->warden->is($this->user)->all(RoleName::Editor))->toBeTrue()
        ->and($this->warden->is($this->user)->notAn(RoleName::Admin))->toBeTrue()
        ->and($this->user->isA(RoleName::Editor))->toBeTrue()
        ->and($this->user->isAll(RoleName::Editor))->toBeTrue()
        ->and($this->user->isNotAn(RoleName::Admin))->toBeTrue()
        ->and(User::query()->whereIs(RoleName::Editor)->count())->toBe(1)
        ->and(User::query()->whereIsAll(RoleName::Editor)->count())->toBe(1)
        ->and(User::query()->whereIsNot(RoleName::Admin)->count())->toBe(1);
});

it('accepts backed enums in role-name authorities, syncs and finders', function (): void {
    $this->warden->allow(RoleName::Admin)->to(PermissionName::Publish);
    $this->warden->sync($this->user)->roles([RoleName::Admin]);
    $this->warden->sync(RoleName::Admin)->permissions([PermissionName::Publish, 'audit']);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('audit'))->toBeTrue()
        ->and($this->warden->findRole(RoleName::Admin)->getAttribute('name'))->toBe('admin')
        ->and($this->warden->findPermission(PermissionName::Publish)->getAttribute('name'))->toBe('publish');
});

it('accepts backed enums in ownership grants', function (): void {
    $account = ElPandaPe\Warden\Tests\Fixtures\Account::query()
        ->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])
        ->refresh();

    $this->warden->allow($this->user)->toOwn(ElPandaPe\Warden\Tests\Fixtures\Account::class, PermissionName::EditSite);

    expect(Gate::forUser($this->user)->allows('edit-site', $account))->toBeTrue();
});
