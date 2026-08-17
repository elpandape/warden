<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\KeylessAccount;
use ElPandaPe\Bouncer\Tests\Fixtures\User;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('revokes blanket grants without touching ownership grants', function (): void {
    $this->bouncer->allow($this->user)->to('edit', Account::class);
    $this->bouncer->allow($this->user)->toOwn(Account::class, 'edit');

    $this->bouncer->disallow($this->user)->to('edit', Account::class);

    expect(Grant::query()->count())->toBe(1)
        ->and(Permission::query()->where('only_owned', true)->count())->toBe(1);

    $this->bouncer->disallow($this->user)->toOwn(Account::class, 'edit');
    $this->bouncer->disallow($this->user)->toOwnEverything();

    expect(Grant::query()->count())->toBe(0);
});

it('rejects entities whose key is not usable instead of widening the grant', function (): void {
    $broken = KeylessAccount::query()->create(['name' => 'Acme']);

    expect(fn () => $this->bouncer->allow($this->user)->to('edit', $broken))
        ->toThrow(InvalidArgumentException::class, 'int or string key')
        ->and(Permission::query()->count())->toBe(0);
});

it('rejects models of the wrong type as permissions or roles', function (): void {
    $role = Role::query()->create(['name' => 'admin']);
    $permission = Permission::query()->create(['name' => 'edit']);

    expect(fn () => $this->bouncer->allow($this->user)->to([$role]))
        ->toThrow(InvalidArgumentException::class, 'not the configured permission model')
        ->and(fn () => $this->bouncer->assign($permission)->to($this->user))
        ->toThrow(InvalidArgumentException::class, 'not the configured role model')
        ->and(fn () => $this->bouncer->retract($permission)->from($this->user))
        ->toThrow(InvalidArgumentException::class, 'not the configured role model')
        ->and(fn () => $this->bouncer->disallow($this->user)->to([$role]))
        ->toThrow(InvalidArgumentException::class, 'not the configured permission model');
});

it('enforces the model contracts at the config boundary', function (): void {
    config()->set('bouncer.models.grant', ElPandaPe\Bouncer\Tests\Fixtures\Plain::class);
    app()->forgetInstance(Context::class);

    expect(fn () => Context::resolve()->grantClass())
        ->toThrow(InvalidArgumentException::class, 'does not satisfy the grant contract');
});

it('registers morph aliases before the app finishes booting', function (): void {
    // The provider registers aliases in boot(), not only in booted():
    // a write from another provider boot() must already store the alias.
    expect(Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel('bouncer.role'))
        ->toBe(Role::class);
});

it('enforces the permission model contract too', function (): void {
    config()->set('bouncer.models.permission', ElPandaPe\Bouncer\Tests\Fixtures\Plain::class);
    app()->forgetInstance(Context::class);

    expect(fn () => Context::resolve()->permissionClass())
        ->toThrow(InvalidArgumentException::class, 'does not satisfy the permission contract');
});
