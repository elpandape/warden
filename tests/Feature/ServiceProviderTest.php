<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\BouncerServiceProvider;
use ElPandaPe\Bouncer\Context;
use Illuminate\Support\ServiceProvider;

it('merges the package configuration', function (): void {
    expect(config('bouncer.tables.roles'))->toBe('roles')
        ->and(config('bouncer.tables.grants'))->toBe('grants')
        ->and(config('bouncer.events_enabled'))->toBeTrue()
        ->and(config('bouncer.cache.prefix'))->toBe('bouncer');
});

it('registers the context as a singleton hydrated from config', function (): void {
    $context = app(Context::class);

    expect($context)->toBeInstanceOf(Context::class)
        ->and($context->table('roles'))->toBe('roles')
        ->and($context->morphAlias('role'))->toBe('bouncer.role')
        ->and(app(Context::class))->toBe($context);
});

it('hydrates the context even when the config is missing', function (): void {
    config()->offsetUnset('bouncer');
    app()->forgetInstance(Context::class);

    expect(app(Context::class)->table('roles'))->toBe('roles');
});

it('loads the english and spanish translations', function (): void {
    expect(trans('bouncer::bouncer.unauthorized'))->toBe('This action is unauthorized.');

    app()->setLocale('es');

    expect(trans('bouncer::bouncer.unauthorized'))->toBe('Esta acción no está autorizada.');
});

it('exposes the publishable config and migration groups', function (): void {
    $config = ServiceProvider::pathsToPublish(BouncerServiceProvider::class, 'bouncer-config');
    $migrations = ServiceProvider::pathsToPublish(BouncerServiceProvider::class, 'bouncer-migrations');

    expect($config)->toHaveCount(1)
        ->and(array_key_first($config))->toEndWith('config/bouncer.php')
        ->and($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_bouncer_tables.php.stub')
        ->and((string) current($migrations))->toContain('_create_bouncer_tables.php');
});
