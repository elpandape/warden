<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('revokes a granted permission without touching forbids', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->forbid($this->user)->to('edit-site');

    $this->warden->disallow($this->user)->to('edit-site');

    expect(Grant::query()->where('forbidden', false)->count())->toBe(0)
        ->and(Grant::query()->where('forbidden', true)->count())->toBe(1);
});

it('unforbids without touching plain grants', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->forbid($this->user)->to('edit-site');

    $this->warden->unforbid($this->user)->to('edit-site');

    expect(Grant::query()->where('forbidden', true)->count())->toBe(0)
        ->and(Grant::query()->where('forbidden', false)->count())->toBe(1);
});

it('revokes entity-scoped permissions precisely', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->warden->allow($this->user)->to('edit', $account)->to('edit', Account::class);
    $this->warden->disallow($this->user)->to('edit', $account);

    expect(Grant::query()->count())->toBe(1);
});

it('revokes everyone grants only from everyone', function (): void {
    $this->warden->allowEveryone()->to('browse');
    $this->warden->allow($this->user)->to('browse');

    $this->warden->disallowEveryone()->to('browse');

    expect(Grant::query()->count())->toBe(1)
        ->and(Grant::query()->sole()->getAttribute('entity_id'))->toBe($this->user->getKey());
});

it('is a no-op for permissions that do not exist', function (): void {
    $this->warden->disallow($this->user)->to('missing');

    expect(Grant::query()->count())->toBe(0);
});

it('supports everything and toManage on the revoke side', function (): void {
    $this->warden->allow($this->user)->everything()->toManage(Account::class);

    $this->warden->disallow($this->user)->everything();
    $this->warden->disallow($this->user)->toManage(Account::class);

    expect(Grant::query()->count())->toBe(0);
});

it('fails fast when revoking from a role that does not exist', function (): void {
    $this->warden->disallow('ghost-role')->to('edit-site');
})->throws(ModelNotFoundException::class);

it('revokes permissions given as models', function (): void {
    $this->warden->allow($this->user)->to('edit-site');
    $permission = ElPandaPe\Warden\Models\Permission::query()->where('name', 'edit-site')->sole();

    $this->warden->disallow($this->user)->to([$permission]);

    expect(Grant::query()->count())->toBe(0);
});
