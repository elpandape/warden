<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\KeylessAccount;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('revokes blanket grants without touching ownership grants', function (): void {
    $this->warden->allow($this->user)->to('edit', Account::class);
    $this->warden->allow($this->user)->toOwn(Account::class, 'edit');

    $this->warden->disallow($this->user)->to('edit', Account::class);

    expect(Grant::query()->count())->toBe(1)
        ->and(Permission::query()->where('only_owned', true)->count())->toBe(1);

    $this->warden->disallow($this->user)->toOwn(Account::class, 'edit');
    $this->warden->disallow($this->user)->toOwnEverything();

    expect(Grant::query()->count())->toBe(0);
});

it('rejects entities whose key is not usable instead of widening the grant', function (): void {
    $broken = KeylessAccount::query()->create(['name' => 'Acme']);

    expect(fn () => $this->warden->allow($this->user)->to('edit', $broken))
        ->toThrow(InvalidArgumentException::class, 'int or string key')
        ->and(Permission::query()->count())->toBe(0);
});

it('rejects models of the wrong type as permissions or roles', function (): void {
    $role = Role::query()->create(['name' => 'admin']);
    $permission = Permission::query()->create(['name' => 'edit']);

    expect(fn () => $this->warden->allow($this->user)->to([$role]))
        ->toThrow(InvalidArgumentException::class, 'not the configured permission model')
        ->and(fn () => $this->warden->assign($permission)->to($this->user))
        ->toThrow(InvalidArgumentException::class, 'not the configured role model')
        ->and(fn () => $this->warden->retract($permission)->from($this->user))
        ->toThrow(InvalidArgumentException::class, 'not the configured role model')
        ->and(fn () => $this->warden->disallow($this->user)->to([$role]))
        ->toThrow(InvalidArgumentException::class, 'not the configured permission model');
});

it('enforces the model contracts at the config boundary', function (): void {
    config()->set('warden.models.grant', ElPandaPe\Warden\Tests\Fixtures\Plain::class);
    app()->forgetInstance(Context::class);

    expect(fn () => Context::resolve()->grantClass())
        ->toThrow(InvalidArgumentException::class, 'does not satisfy the grant contract');
});

it('registers morph aliases before the app finishes booting', function (): void {
    // The provider registers aliases in boot(), not only in booted():
    // a write from another provider boot() must already store the alias.
    expect(Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel('warden.role'))
        ->toBe(Role::class);
});

it('enforces the permission model contract too', function (): void {
    config()->set('warden.models.permission', ElPandaPe\Warden\Tests\Fixtures\Plain::class);
    app()->forgetInstance(Context::class);

    expect(fn () => Context::resolve()->permissionClass())
        ->toThrow(InvalidArgumentException::class, 'does not satisfy the permission contract');
});
