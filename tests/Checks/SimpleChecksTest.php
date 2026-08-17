<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('authorizes granted permissions through the gate', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('other'))->toBeFalse();
});

it('stops authorizing after a revocation', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->disallow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('lets forbid beat any grant', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->forbid($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->bouncer->unforbid($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('authorizes through roles', function (): void {
    $this->bouncer->allow('admin')->to('scale-site');
    $this->bouncer->assign('admin')->to($this->user);

    expect(Gate::forUser($this->user)->allows('scale-site'))->toBeTrue();
});

it('lets a forbid on one role beat a grant from another', function (): void {
    $this->bouncer->assign(['banned', 'editor'])->to($this->user);
    $this->bouncer->allow('editor')->to('publish');
    $this->bouncer->forbid('banned')->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('authorizes everyone grants for any authority', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->bouncer->allowEveryone()->to('browse');

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('browse'))->toBeTrue();

    $this->bouncer->forbidEveryone()->to('browse');

    expect(Gate::forUser($this->user)->allows('browse'))->toBeFalse();
});

it('exposes checks on the bouncer for the authenticated user', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    $this->actingAs($this->user);

    expect($this->bouncer->can('edit-site'))->toBeTrue()
        ->and($this->bouncer->cannot('other'))->toBeTrue()
        ->and($this->bouncer->canAny(['other', 'edit-site']))->toBeTrue()
        ->and($this->bouncer->authorize('edit-site')->allowed())->toBeTrue();
});
