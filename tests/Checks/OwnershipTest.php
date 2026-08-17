<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    // refresh(): freshly created models lack unset columns in their attributes.
    $this->owned = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $this->foreign = Account::query()->create(['name' => 'Other'])->refresh();
});

afterEach(function (): void {
    Model::preventAccessingMissingAttributes(false);
});

it('authorizes ownership grants for owned entities only', function (): void {
    $this->bouncer->allow($this->user)->toOwn(Account::class);

    expect(Gate::forUser($this->user)->allows('edit', $this->owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->foreign))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse();
});

it('limits ownership grants to the listed permissions', function (): void {
    $this->bouncer->allow($this->user)->toOwn(Account::class, ['view']);

    expect(Gate::forUser($this->user)->allows('view', $this->owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->owned))->toBeFalse();
});

it('authorizes everything owned with toOwnEverything', function (): void {
    $this->bouncer->allow($this->user)->toOwnEverything();

    expect(Gate::forUser($this->user)->allows('whatever', $this->owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('whatever', $this->foreign))->toBeFalse();
});

it('resolves ownership through a global custom attribute', function (): void {
    $this->bouncer->ownedVia('owner_id');
    $byOwner = Account::query()->create(['name' => 'Held', 'owner_id' => $this->user->getKey()]);

    $this->bouncer->allow($this->user)->toOwn(Account::class);

    expect(Gate::forUser($this->user)->allows('edit', $byOwner))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->owned))->toBeFalse();
});

it('resolves ownership per entity class', function (): void {
    $this->bouncer->ownedVia(Account::class, 'owner_id');
    $byOwner = Account::query()->create(['name' => 'Held', 'owner_id' => $this->user->getKey()]);

    $this->bouncer->allow($this->user)->toOwn(Account::class);

    expect(Gate::forUser($this->user)->allows('edit', $byOwner))->toBeTrue();
});

it('resolves ownership with a closure', function (): void {
    $this->bouncer->ownedVia(fn (Model $entity, Model $authority): bool => $entity->getAttribute('name') === 'Mine');

    $this->bouncer->allow($this->user)->toOwn(Account::class);

    expect(Gate::forUser($this->user)->allows('edit', $this->owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->foreign))->toBeFalse();
});

it('stays safe under strict mode when the attribute is missing', function (): void {
    Model::preventAccessingMissingAttributes();

    $other = User::query()->create(['name' => 'Ana']);
    $this->bouncer->allow($this->user)->toOwnEverything();

    // The users table has no user_id column: not owned, never an exception.
    expect(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse();
});

it('lets strict mode surface when the safety valve is disabled', function (): void {
    config()->set('bouncer.ownership.strict_mode_safe', false);
    Model::preventAccessingMissingAttributes();

    // A freshly retrieved model: recently-created ones bypass strict mode.
    User::query()->create(['name' => 'Ana']);
    $other = User::query()->where('name', 'Ana')->sole();

    $this->bouncer->allow($this->user)->toOwnEverything();

    Gate::forUser($this->user)->allows('edit', $other);
})->throws(Illuminate\Database\Eloquent\MissingAttributeException::class);

it('rejects closures passed as per-model classes', function (): void {
    $this->bouncer->ownedVia(fn (): bool => true, 'user_id');
})->throws(InvalidArgumentException::class, 'entity class as a string');

it('returns not-owned quietly for recently created models even when opted out', function (): void {
    config()->set('bouncer.ownership.strict_mode_safe', false);
    Model::preventAccessingMissingAttributes();

    // Recently created models bypass strict mode: the probe returns null, not owned.
    $other = User::query()->create(['name' => 'Ana']);
    $this->bouncer->allow($this->user)->toOwnEverything();

    expect(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse();
});
