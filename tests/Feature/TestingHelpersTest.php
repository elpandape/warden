<?php

declare(strict_types=1);

use ElPandaPe\Warden\Testing\WardenFake;
use ElPandaPe\Warden\Testing\WithPermissions;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\AssertionFailedError;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

pest()->use(WithPermissions::class);

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('scripts verdicts without touching the database', function (): void {
    $fake = $this->warden->fake();
    $account = Account::query()->create(['name' => 'A'])->refresh();

    $fake->allow('edit-site');
    $fake->allow('edit', Account::class);
    $fake->forbid('delete');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('delete'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('unscripted'))->toBeFalse();
});

it('applies forbidden-first among scripted rules', function (): void {
    $fake = $this->warden->fake();

    $fake->allow('publish')->forbid('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('leaves unscripted checks to the app policies', function (): void {
    $this->warden->fake();

    Gate::define('special', fn (User $user): bool => true);

    expect(Gate::forUser($this->user)->allows('special'))->toBeTrue();
});

it('records checks for assertions', function (): void {
    $fake = $this->warden->fake();
    $fake->allow('edit-site')->forbid('delete');

    Gate::forUser($this->user)->allows('edit-site');
    Gate::forUser($this->user)->allows('delete');

    $fake->assertChecked('edit-site');
    $fake->assertGranted('edit-site');
    $fake->assertForbidden('delete');
    $fake->assertNotChecked('never-touched');

    expect(fn () => $fake->assertNotChecked('edit-site'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertGranted('delete'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertForbidden('edit-site'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingChecked())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertChecked('never-touched'))->toThrow(AssertionFailedError::class);
});

it('asserts silence when nothing was checked', function (): void {
    $fake = $this->warden->fake();

    $fake->assertNothingChecked();
    expect($fake)->toBeInstanceOf(WardenFake::class);
});

it('arranges real permissions through the testing trait', function (): void {
    $account = Account::query()->create(['name' => 'A'])->refresh();

    $this->allowUser($this->user, 'view', Account::class);
    $this->forbidUser($this->user, 'view', $account);
    $this->assignRoles($this->user, 'admin');

    $other = Account::query()->create(['name' => 'B'])->refresh();

    expect(Gate::forUser($this->user)->allows('view', $other))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $account))->toBeFalse()
        ->and($this->user->isAn('admin'))->toBeTrue();
});

it('does not answer an entity-scoped check with a rule that named no entity', function (): void {
    $fake = $this->warden->fake();
    $fake->allow('edit');

    // Warden's own matrix: a permission with no entity answers instance-less
    // checks only. The fake must not be looser than the thing it fakes.
    expect(Gate::forUser($this->user)->allows('edit'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse();
});

it('scripts a condition given as a column and a value', function (): void {
    $fake = $this->warden->fake();
    $mine = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $theirs = Account::query()->create(['name' => 'Theirs'])->refresh();

    $fake->allow('edit', Account::class)->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('edit', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $theirs))->toBeFalse();
});

it('scripts a condition comparing the entity against the authority', function (): void {
    $fake = $this->warden->fake();
    $mine = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $theirs = Account::query()->create(['name' => 'Theirs'])->refresh();

    $fake->allow('edit', Account::class)->whereColumn('user_id', 'id');

    expect(Gate::forUser($this->user)->allows('edit', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $theirs))->toBeFalse();
});

it('refuses to narrow a rule that was never scripted', function (): void {
    expect(fn () => $this->warden->fake()->for($this->user))
        ->toThrow(LogicException::class, 'Script a rule with allow() or forbid() before narrowing it.');
});
