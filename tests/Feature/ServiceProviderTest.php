<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the package configuration', function (): void {
    expect(config('warden.tables.roles'))->toBe('roles')
        ->and(config('warden.tables.grants'))->toBe('grants')
        ->and(config('warden.events_enabled'))->toBeTrue()
        ->and(config('warden.cache.prefix'))->toBe('warden');
});

it('registers the context as a singleton hydrated from config', function (): void {
    $context = app(Context::class);

    expect($context)->toBeInstanceOf(Context::class)
        ->and($context->table('roles'))->toBe('roles')
        ->and($context->morphAlias('role'))->toBe('warden.role')
        ->and(app(Context::class))->toBe($context);
});

it('hydrates the context even when the config is missing', function (): void {
    config()->offsetUnset('warden');
    app()->forgetInstance(Context::class);

    expect(app(Context::class)->table('roles'))->toBe('roles');
});

it('loads the english and spanish translations', function (): void {
    expect(trans('warden::warden.unauthorized'))->toBe('This action is unauthorized.');

    app()->setLocale('es');

    expect(trans('warden::warden.unauthorized'))->toBe('Esta acción no está autorizada.');
});

it('exposes the publishable config and migration groups', function (): void {
    $config = ServiceProvider::pathsToPublish(WardenServiceProvider::class, 'warden-config');
    $migrations = ServiceProvider::pathsToPublish(WardenServiceProvider::class, 'warden-migrations');

    expect($config)->toHaveCount(1)
        ->and(array_key_first($config))->toEndWith('config/warden.php')
        ->and($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_warden_tables.php.stub')
        ->and((string) current($migrations))->toContain('_create_warden_tables.php');
});
