<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('revokes a granted permission without touching forbids', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->forbid($this->user)->to('edit-site');

    $this->bouncer->disallow($this->user)->to('edit-site');

    expect(Grant::query()->where('forbidden', false)->count())->toBe(0)
        ->and(Grant::query()->where('forbidden', true)->count())->toBe(1);
});

it('unforbids without touching plain grants', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->forbid($this->user)->to('edit-site');

    $this->bouncer->unforbid($this->user)->to('edit-site');

    expect(Grant::query()->where('forbidden', true)->count())->toBe(0)
        ->and(Grant::query()->where('forbidden', false)->count())->toBe(1);
});

it('revokes entity-scoped permissions precisely', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->bouncer->allow($this->user)->to('edit', $account)->to('edit', Account::class);
    $this->bouncer->disallow($this->user)->to('edit', $account);

    expect(Grant::query()->count())->toBe(1);
});

it('revokes everyone grants only from everyone', function (): void {
    $this->bouncer->allowEveryone()->to('browse');
    $this->bouncer->allow($this->user)->to('browse');

    $this->bouncer->disallowEveryone()->to('browse');

    expect(Grant::query()->count())->toBe(1)
        ->and(Grant::query()->sole()->getAttribute('entity_id'))->toBe($this->user->getKey());
});

it('is a no-op for permissions that do not exist', function (): void {
    $this->bouncer->disallow($this->user)->to('missing');

    expect(Grant::query()->count())->toBe(0);
});

it('supports everything and toManage on the revoke side', function (): void {
    $this->bouncer->allow($this->user)->everything()->toManage(Account::class);

    $this->bouncer->disallow($this->user)->everything();
    $this->bouncer->disallow($this->user)->toManage(Account::class);

    expect(Grant::query()->count())->toBe(0);
});

it('fails fast when revoking from a role that does not exist', function (): void {
    $this->bouncer->disallow('ghost-role')->to('edit-site');
})->throws(ModelNotFoundException::class);

it('revokes permissions given as models', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');
    $permission = ElPandaPe\Bouncer\Database\Permission::query()->where('name', 'edit-site')->sole();

    $this->bouncer->disallow($this->user)->to([$permission]);

    expect(Grant::query()->count())->toBe(0);
});
