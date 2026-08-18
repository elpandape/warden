<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->user->roles()->attach(Role::query()->create(['name' => 'admin']));
    $this->user->roles()->attach(Role::query()->create(['name' => 'editor']));
});

it('checks whether the authority has any of the given roles', function (): void {
    expect($this->user->isA('admin'))->toBeTrue()
        ->and($this->user->isAn('editor'))->toBeTrue()
        ->and($this->user->isA('subscriber', 'admin'))->toBeTrue()
        ->and($this->user->isA('subscriber'))->toBeFalse();
});

it('checks whether the authority lacks the given roles', function (): void {
    expect($this->user->isNotA('subscriber'))->toBeTrue()
        ->and($this->user->isNotAn('admin'))->toBeFalse();
});

it('checks whether the authority has all of the given roles', function (): void {
    expect($this->user->isAll('admin', 'editor'))->toBeTrue()
        ->and($this->user->isAll('admin'))->toBeTrue()
        ->and($this->user->isAll('admin', 'subscriber'))->toBeFalse();
});

it('treats duplicated role names as one requirement', function (): void {
    expect($this->user->isAll('admin', 'admin'))->toBeTrue();
});

it('answers from the eager-loaded relation without extra queries', function (): void {
    $this->user->load('roles');

    app('db')->enableQueryLog();

    expect($this->user->isA('admin'))->toBeTrue()
        ->and($this->user->isA('subscriber'))->toBeFalse()
        ->and($this->user->isAll('admin', 'editor'))->toBeTrue()
        ->and($this->user->isAll('admin', 'subscriber'))->toBeFalse()
        ->and(app('db')->getQueryLog())->toBeEmpty();

    app('db')->disableQueryLog();
});

it('lists granted and forbidden permissions for migrators', function (): void {
    $bouncer = app(ElPandaPe\Bouncer\Bouncer::class);
    $user = User::query()->create(['name' => 'Lister']);
    $org = ElPandaPe\Bouncer\Tests\Fixtures\Account::query()->create(['name' => 'Org'])->refresh();

    $bouncer->allow($user)->to('direct');
    $bouncer->allow('lister-role')->to('via-role');
    $bouncer->assign('lister-role')->to($user);
    $bouncer->allowEveryone()->to('shared');
    $bouncer->forbid($user)->to('banned');

    // Restricted contexts are excluded from the flat listing, fail-closed.
    $bouncer->allow('scoped-role')->to('scoped-only');
    $bouncer->assign('scoped-role')->on($org)->to($user);

    expect($user->getPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['direct', 'shared', 'via-role'])
        ->and($user->getForbiddenPermissions()->pluck('name')->all())->toBe(['banned']);
});
