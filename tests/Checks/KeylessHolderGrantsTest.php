<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CachedResolver;
use ElPandaPe\Warden\Checks\Resolvers\DatabaseResolver;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->account = Account::query()->create(['name' => 'Acme'])->refresh();

    $ghost = User::query()->create(['name' => 'Ghost']);

    $this->warden->allow($ghost)->to('delete-site');
    $this->warden->allow($ghost)->to('edit', Account::class);
    $this->warden->forbid($ghost)->to('publish');
    $this->warden->forbid($ghost)->to('view', Account::class);
    $this->warden->allow('keyless')->to('audit');

    Grant::query()->withoutGlobalScopes()->update(['entity_id' => null]);

    $this->warden->allow($this->user)->to('publish');
    $this->warden->allow($this->user)->to('view', Account::class);
});

it('lets no grant or forbid planted for a keyless holder reach a user in the database resolver', function (): void {
    $resolver = new DatabaseResolver(app(Context::class));

    expect($resolver->resolve($this->user, 'delete-site')->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'audit')->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'edit', $this->account)->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'publish')->isGranted())->toBeTrue()
        ->and($resolver->resolve($this->user, 'view', $this->account)->isGranted())->toBeTrue();
});

it('still lets an everyone-grant and an everyone-forbid reach a user in the database resolver', function (): void {
    $this->warden->allowEveryone()->to('browse');
    $this->warden->forbidEveryone()->to('publish');

    $resolver = new DatabaseResolver(app(Context::class));

    expect($resolver->resolve($this->user, 'browse')->isGranted())->toBeTrue()
        ->and($resolver->resolve($this->user, 'publish')->isForbidden())->toBeTrue();
});

it('lets no grant or forbid planted for a keyless holder reach a user in the cached resolver', function (): void {
    config()->set('warden.cache.enabled', true);

    $resolver = app(Resolver::class);

    expect($resolver)->toBeInstanceOf(CachedResolver::class)
        ->and($resolver->resolve($this->user, 'delete-site')->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'audit')->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'edit', $this->account)->isAbstained())->toBeTrue()
        ->and($resolver->resolve($this->user, 'publish')->isGranted())->toBeTrue()
        ->and($resolver->resolve($this->user, 'view', $this->account)->isGranted())->toBeTrue();
});

it('still lets an everyone-grant and an everyone-forbid reach a user in the cached resolver', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->allowEveryone()->to('browse');
    $this->warden->forbidEveryone()->to('publish');

    $resolver = app(Resolver::class);

    expect($resolver)->toBeInstanceOf(CachedResolver::class)
        ->and($resolver->resolve($this->user, 'browse')->isGranted())->toBeTrue()
        ->and($resolver->resolve($this->user, 'publish')->isForbidden())->toBeTrue();
});

it('lets no grant or forbid planted for a keyless holder reach a user in whereCan', function (): void {
    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(0)
        ->and(Account::query()->whereCan($this->user, 'view')->pluck('name')->all())->toBe(['Acme'])
        ->and($this->user)->toQueryExactlyWhatItCanCheck('edit')
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('still lets an everyone-grant and an everyone-forbid reach a user in whereCan', function (): void {
    $this->warden->allowEveryone()->to('browse', Account::class);
    $this->warden->forbidEveryone()->to('view', $this->account);

    expect(Account::query()->whereCan($this->user, 'browse')->pluck('name')->all())->toBe(['Acme'])
        ->and(Account::query()->whereCan($this->user, 'view')->count())->toBe(0)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('browse')
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('lists no permission planted for a keyless holder', function (): void {
    expect($this->user->getPermissions()->pluck('name')->sort()->values()->all())->toBe(['publish', 'view'])
        ->and($this->user->getForbiddenPermissions())->toBeEmpty();
});

it('still lists an everyone-grant and an everyone-forbid for a user', function (): void {
    $this->warden->allowEveryone()->to('browse');
    $this->warden->forbidEveryone()->to('archive');

    expect($this->user->getPermissions()->pluck('name')->sort()->values()->all())->toBe(['browse', 'publish', 'view'])
        ->and($this->user->getForbiddenPermissions()->pluck('name')->all())->toBe(['archive']);
});

it('sweeps the rows planted for a keyless holder with warden:clean --stranded', function (): void {
    $this->warden->allowEveryone()->to('browse');

    $this->artisan('warden:clean', ['--stranded' => true])->assertSuccessful();

    expect(Grant::query()->withoutGlobalScopes()->whereNotNull('entity_type')->whereNull('entity_id')->count())->toBe(0)
        ->and(Grant::query()->withoutGlobalScopes()->count())->toBe(3);
});
