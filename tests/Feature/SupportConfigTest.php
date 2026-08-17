<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Enums\GateSlot;
use ElPandaPe\Bouncer\Support\Config;

it('reads events_enabled', function (): void {
    expect(Config::eventsEnabled())->toBeTrue();

    config()->set('bouncer.events_enabled', false);

    expect(Config::eventsEnabled())->toBeFalse();
});

it('reads gate.register', function (): void {
    expect(Config::gateRegisters())->toBeTrue();

    config()->set('bouncer.gate.register', false);

    expect(Config::gateRegisters())->toBeFalse();
});

it('maps run_before_policies to a gate slot', function (): void {
    expect(Config::gateSlot())->toBe(GateSlot::After);

    config()->set('bouncer.gate.run_before_policies', true);

    expect(Config::gateSlot())->toBe(GateSlot::Before);
});

it('reads titles.autogenerate', function (): void {
    expect(Config::titlesAutogenerate())->toBeTrue();

    config()->set('bouncer.titles.autogenerate', false);

    expect(Config::titlesAutogenerate())->toBeFalse();
});

it('normalizes scope.null_behavior to all or strict', function (): void {
    expect(Config::scopeNullBehavior())->toBe('all');

    config()->set('bouncer.scope.null_behavior', 'strict');
    expect(Config::scopeNullBehavior())->toBe('strict');

    config()->set('bouncer.scope.null_behavior', 'nonsense');
    expect(Config::scopeNullBehavior())->toBe('all');
});

it('reads ownership.strict_mode_safe', function (): void {
    expect(Config::ownershipStrictModeSafe())->toBeTrue();

    config()->set('bouncer.ownership.strict_mode_safe', false);

    expect(Config::ownershipStrictModeSafe())->toBeFalse();
});

it('reads the exception display flags', function (): void {
    expect(Config::displayPermissionInException())->toBeFalse()
        ->and(Config::displayRoleInException())->toBeFalse();

    config()->set('bouncer.exceptions.display_permission_in_exception', true);
    config()->set('bouncer.exceptions.display_role_in_exception', true);

    expect(Config::displayPermissionInException())->toBeTrue()
        ->and(Config::displayRoleInException())->toBeTrue();
});

it('reads octane.register_reset_listener', function (): void {
    expect(Config::registersOctaneResetListener())->toBeTrue();

    config()->set('bouncer.octane.register_reset_listener', false);

    expect(Config::registersOctaneResetListener())->toBeFalse();
});
