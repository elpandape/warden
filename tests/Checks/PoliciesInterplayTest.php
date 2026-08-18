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
});

it('lets app definitions win in the default after slot', function (): void {
    Gate::define('publish', fn (): bool => true);
    $this->warden->forbid($this->user)->to('publish');

    // The app said yes: in the after slot Warden never overrides it.
    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('abstains when the app definition denies', function (): void {
    Gate::define('publish', fn (): bool => false);
    $this->warden->allow($this->user)->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('vetoes app definitions when configured to run before policies', function (): void {
    config()->set('warden.gate.run_before_policies', true);

    Gate::define('publish', fn (): bool => true);
    $this->warden->forbid($this->user)->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('grants before app definitions when configured to run first', function (): void {
    config()->set('warden.gate.run_before_policies', true);

    Gate::define('publish', fn (): bool => false);
    $this->warden->allow($this->user)->to('publish');

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('abstains on checks with extra arguments', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    Gate::define('transfer', fn (User $user, Account $target, int $amount): bool => $amount < 100);
    $this->warden->forbid($this->user)->everything();

    // Multi-argument checks belong to app policies; Warden never interferes.
    expect(Gate::forUser($this->user)->allows('transfer', [$account, 50]))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('transfer', [$account, 500]))->toBeFalse();
});

it('abstains for guests and non-model arguments', function (): void {
    $this->warden->allowEveryone()->to('browse');

    expect(Gate::forUser(null)->allows('browse'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('browse', [123]))->toBeFalse();
});

it('stays out of the gate when registration is disabled', function (): void {
    config()->set('warden.gate.register', false);

    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('abstains on extra arguments even when running before policies', function (): void {
    config()->set('warden.gate.run_before_policies', true);

    $account = Account::query()->create(['name' => 'Acme']);
    $this->warden->allow($this->user)->everything();

    // Even a full wildcard must not answer a multi-argument check.
    expect(Gate::forUser($this->user)->allows('transfer', [$account, 500]))->toBeFalse();
});

it('applies ownership forbids to owners only', function (): void {
    $owned = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()]);
    $foreign = Account::query()->create(['name' => 'Other']);

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->to($this->user);
    $this->warden->forbid($this->user)->toOwn(Account::class, 'edit');

    expect(Gate::forUser($this->user)->allows('edit', $owned))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', $foreign))->toBeTrue();
});

it('denies with a translated message', function (): void {
    $this->warden->forbid($this->user)->to('publish');

    app()->setLocale('es');

    $response = Gate::forUser($this->user)->inspect('publish');

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toBe('Esta acción no está autorizada.');
});
