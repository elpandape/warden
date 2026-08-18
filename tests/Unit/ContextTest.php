<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;

it('resolves configured table names and falls back to the given name', function (): void {
    $context = Context::fromConfig(['tables' => ['roles' => 'custom_roles']]);

    expect($context->table('roles'))->toBe('custom_roles')
        ->and($context->table('permissions'))->toBe('permissions');
});

it('allows overriding a table at runtime', function (): void {
    $context = Context::fromConfig([]);

    $context->setTable('roles', 'tenant_roles');

    expect($context->table('roles'))->toBe('tenant_roles');
});

it('exposes the configured connection', function (): void {
    expect(Context::fromConfig(['connection' => 'tenant'])->connection())->toBe('tenant')
        ->and(Context::fromConfig([])->connection())->toBeNull()
        ->and(Context::fromConfig(['connection' => 123])->connection())->toBeNull();
});

it('allows overriding the connection at runtime', function (): void {
    $context = Context::fromConfig(['connection' => 'tenant']);

    $context->setConnection(null);

    expect($context->connection())->toBeNull();
});

it('resolves morph aliases', function (): void {
    $context = Context::fromConfig(['morph_aliases' => ['role' => 'warden.role']]);

    expect($context->morphAlias('role'))->toBe('warden.role')
        ->and($context->morphAlias('missing'))->toBeNull();
});

it('resolves the ownership attribute with a default', function (): void {
    expect(Context::fromConfig([])->ownershipAttribute())->toBe('user_id')
        ->and(Context::fromConfig(['ownership' => ['default_attribute' => 'owner_id']])->ownershipAttribute())->toBe('owner_id')
        ->and(Context::fromConfig(['ownership' => 'invalid'])->ownershipAttribute())->toBe('user_id')
        ->and(Context::fromConfig(['ownership' => ['default_attribute' => 42]])->ownershipAttribute())->toBe('user_id');
});

it('ignores non-string entries in string maps', function (): void {
    $context = Context::fromConfig([
        'tables' => ['roles' => 'ok', 'grants' => 42, 0 => 'indexed'],
        'morph_aliases' => 'not-an-array',
    ]);

    expect($context->table('roles'))->toBe('ok')
        ->and($context->table('grants'))->toBe('grants')
        ->and($context->morphAlias('role'))->toBeNull();
});

it('fails fast on empty configured table names', function (): void {
    config()->set('warden.tables.permissions', '');
    app()->forgetInstance(Context::class);

    // Without this guard the misconfiguration surfaces later as broken
    // SQL with an empty identifier, far from its cause.
    expect(fn () => Context::resolve()->table('permissions'))
        ->toThrow(ElPandaPe\Warden\Exceptions\ConfigurationException::class, 'must not be empty');

    expect(fn () => Context::resolve()->setTable('roles', ''))
        ->not->toThrow(Exception::class)
        ->and(fn () => Context::resolve()->table('roles'))
        ->toThrow(ElPandaPe\Warden\Exceptions\ConfigurationException::class);
});
