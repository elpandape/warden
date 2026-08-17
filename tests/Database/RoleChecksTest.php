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
