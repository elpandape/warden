<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->account = Account::query()->create(['name' => 'Acme']);
});

it('grants class-wide permissions for every instance and the class itself', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);

    $other = Account::query()->create(['name' => 'Globex']);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $other))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('view', $this->user))->toBeFalse();
});

it('grants instance permissions for that instance only', function (): void {
    $other = Account::query()->create(['name' => 'Globex']);

    $this->warden->allow($this->user)->to('edit', $this->account);

    expect(Gate::forUser($this->user)->allows('edit', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit'))->toBeFalse();
});

it('forbids one instance out of a class-wide grant', function (): void {
    $classified = Account::query()->create(['name' => 'Classified']);

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', $classified);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $classified))->toBeFalse();
});

it('matches the everything wildcard on every shape', function (): void {
    $this->warden->allow($this->user)->everything();

    expect(Gate::forUser($this->user)->allows('anything'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeTrue();
});

it('matches an action wildcard across entities but not simple checks', function (): void {
    $this->warden->allow($this->user)->to('audit', '*');

    expect(Gate::forUser($this->user)->allows('audit', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('audit', $this->user))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('audit'))->toBeFalse();
});

it('matches a manage wildcard for one entity type only', function (): void {
    $this->warden->allow($this->user)->toManage(Account::class);

    expect(Gate::forUser($this->user)->allows('delete', $this->account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('delete', $this->user))->toBeFalse();
});

it('treats a simple wildcard as all simple permissions', function (): void {
    $this->warden->allow($this->user)->to('*');

    expect(Gate::forUser($this->user)->allows('whatever'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->account))->toBeFalse();
});

it('does not authorize ownership-scoped grants yet', function (): void {
    // Ownership resolution arrives in v0.5.0: until then, toOwn() rows never match.
    $this->warden->allow($this->user)->toOwn(Account::class);

    expect(Gate::forUser($this->user)->allows('edit', $this->account))->toBeFalse();
});

it('matches the action wildcard when asked with a literal star', function (): void {
    $this->warden->allow($this->user)->to('audit', '*');

    expect(Gate::forUser($this->user)->allows('audit', '*'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('other', '*'))->toBeFalse();
});

it('abstains on string arguments that are not model classes', function (): void {
    $this->warden->allow($this->user)->everything();

    // Non-class strings belong to app policies: Warden must not throw nor grant.
    expect(Gate::forUser($this->user)->allows('edit', 'Not\A\Model'))->toBeFalse();
});
