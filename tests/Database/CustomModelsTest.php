<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\CustomRole;
use ElPandaPe\Warden\Tests\Fixtures\User;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

it('resolves custom models from the configuration', function (): void {
    config()->set('warden.models.role', CustomRole::class);
    app()->forgetInstance(Context::class);

    expect(Context::resolve()->modelClass('role'))->toBe(CustomRole::class);
});

it('fails fast when the override is not an eloquent model class', function (): void {
    config()->set('warden.models.role', 'Not\A\Real\Class');
    app()->forgetInstance(Context::class);

    Context::resolve()->modelClass('role');
})->throws(InvalidArgumentException::class, 'Configured warden model [role]');

it('throws for unknown model keys', function (): void {
    Context::resolve()->modelClass('nonsense');
})->throws(InvalidArgumentException::class);

it('resolves the user model from the default auth guard', function (): void {
    config()->set('auth.providers.users.model', User::class);
    app()->forgetInstance(Context::class);

    expect(Context::resolve()->modelClass('user'))->toBe(User::class);
});

it('prefers the configured user model over the auth guard', function (): void {
    config()->set('warden.models.user', ElPandaPe\Warden\Tests\Fixtures\Account::class);
    app()->forgetInstance(Context::class);

    expect(Context::resolve()->modelClass('user'))->toBe(ElPandaPe\Warden\Tests\Fixtures\Account::class);
});

it('asks for explicit configuration when the auth guard has no eloquent user', function (): void {
    config()->set('auth.providers.users.model', 'Not\A\Real\User');
    app()->forgetInstance(Context::class);

    Context::resolve()->modelClass('user');
})->throws(InvalidArgumentException::class, 'warden.models.user');

it('re-registers the morph alias when a model is swapped at runtime', function (): void {
    Context::resolve()->setModelClass('role', CustomRole::class);

    expect(Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel('warden.role'))->toBe(CustomRole::class)
        ->and((new CustomRole)->getMorphClass())->toBe('warden.role');
});

afterEach(function (): void {
    // setModelClass may repoint the static morph map; restore the package default.
    Illuminate\Database\Eloquent\Relations\Relation::morphMap(['warden.role' => Role::class]);
});

it('uses the custom role model across relations', function (): void {
    Context::resolve()->setModelClass('role', CustomRole::class);

    $user = User::query()->create(['name' => 'Joseph']);
    $user->roles()->attach(CustomRole::query()->create(['name' => 'admin']));

    expect($user->roles()->first())->toBeInstanceOf(CustomRole::class)
        ->and($user->isA('admin'))->toBeTrue();
});

it('shares the custom role table with the default one', function (): void {
    expect((new CustomRole)->getTable())->toBe('roles');
});
