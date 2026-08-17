<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Tests\Fixtures\PermissionName;
use ElPandaPe\Bouncer\Tests\Fixtures\RoleName;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('accepts backed enums across the write api', function (): void {
    $this->bouncer->allow($this->user)->to(PermissionName::EditSite);
    $this->bouncer->forbid($this->user)->to(PermissionName::Publish);
    $this->bouncer->assign(RoleName::Admin)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and($this->user->isAn('admin'))->toBeTrue();

    $this->bouncer->unforbid($this->user)->to(PermissionName::Publish);
    $this->bouncer->disallow($this->user)->to(PermissionName::EditSite);
    $this->bouncer->retract(RoleName::Admin)->from($this->user);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and($this->user->isAn('admin'))->toBeFalse();
});

it('accepts backed enums across the check api', function (): void {
    $this->bouncer->allow($this->user)->to(PermissionName::EditSite);
    $this->bouncer->assign(RoleName::Editor)->to($this->user);
    $this->actingAs($this->user);

    expect($this->bouncer->can(PermissionName::EditSite))->toBeTrue()
        ->and($this->bouncer->cannot(PermissionName::Publish))->toBeTrue()
        ->and($this->bouncer->canAny([PermissionName::Publish, 'edit-site']))->toBeTrue()
        ->and($this->bouncer->authorize(PermissionName::EditSite))->not->toBeNull()
        ->and($this->bouncer->is($this->user)->a(RoleName::Editor))->toBeTrue()
        ->and($this->bouncer->is($this->user)->all(RoleName::Editor))->toBeTrue()
        ->and($this->bouncer->is($this->user)->notAn(RoleName::Admin))->toBeTrue()
        ->and($this->user->isA(RoleName::Editor))->toBeTrue()
        ->and($this->user->isAll(RoleName::Editor))->toBeTrue()
        ->and($this->user->isNotAn(RoleName::Admin))->toBeTrue()
        ->and(User::query()->whereIs(RoleName::Editor)->count())->toBe(1)
        ->and(User::query()->whereIsAll(RoleName::Editor)->count())->toBe(1)
        ->and(User::query()->whereIsNot(RoleName::Admin)->count())->toBe(1);
});

it('accepts backed enums in role-name authorities, syncs and finders', function (): void {
    $this->bouncer->allow(RoleName::Admin)->to(PermissionName::Publish);
    $this->bouncer->sync($this->user)->roles([RoleName::Admin]);
    $this->bouncer->sync(RoleName::Admin)->permissions([PermissionName::Publish, 'audit']);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('audit'))->toBeTrue()
        ->and($this->bouncer->findRole(RoleName::Admin)->getAttribute('name'))->toBe('admin')
        ->and($this->bouncer->findPermission(PermissionName::Publish)->getAttribute('name'))->toBe('publish');
});

it('accepts backed enums in ownership grants', function (): void {
    $account = ElPandaPe\Bouncer\Tests\Fixtures\Account::query()
        ->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])
        ->refresh();

    $this->bouncer->allow($this->user)->toOwn(ElPandaPe\Bouncer\Tests\Fixtures\Account::class, PermissionName::EditSite);

    expect(Gate::forUser($this->user)->allows('edit-site', $account))->toBeTrue();
});
