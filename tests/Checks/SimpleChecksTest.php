<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('authorizes granted permissions through the gate', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('other'))->toBeFalse();
});

it('stops authorizing after a revocation', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->disallow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('lets forbid beat any grant', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->forbid($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();

    $this->warden->unforbid($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('authorizes through roles', function (): void {
    $this->warden->allow('admin')->to('scale-site');
    $this->warden->assign('admin')->to($this->user);

    expect(Gate::forUser($this->user)->allows('scale-site'))->toBeTrue();
});

it('lets a forbid on one role beat a grant from another', function (): void {
    $this->warden->assign(['banned', 'editor'])->to($this->user);
    $this->warden->allow('editor')->to('publish');
    $this->warden->forbid('banned')->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('authorizes everyone grants for any authority', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allowEveryone()->to('browse');

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('browse'))->toBeTrue();

    $this->warden->forbidEveryone()->to('browse');

    expect(Gate::forUser($this->user)->allows('browse'))->toBeFalse();
});

it('exposes checks on the warden for the authenticated user', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    $this->actingAs($this->user);

    expect($this->warden->can('edit-site'))->toBeTrue()
        ->and($this->warden->cannot('other'))->toBeTrue()
        ->and($this->warden->canAny(['other', 'edit-site']))->toBeTrue()
        ->and($this->warden->authorize('edit-site')->allowed())->toBeTrue();
});
