<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Support;

use ElPandaPe\Bouncer\Enums\GateSlot;

final class Config
{
    public static function eventsEnabled(): bool
    {
        return (bool) config('bouncer.events_enabled', true);
    }

    public static function gateRegisters(): bool
    {
        return (bool) config('bouncer.gate.register', true);
    }

    public static function gateSlot(): GateSlot
    {
        return config('bouncer.gate.run_before_policies', false)
            ? GateSlot::Before
            : GateSlot::After;
    }

    public static function titlesAutogenerate(): bool
    {
        return (bool) config('bouncer.titles.autogenerate', true);
    }

    public static function scopeNullBehavior(): string
    {
        $value = config('bouncer.scope.null_behavior', 'all');

        return $value === 'strict' ? 'strict' : 'all';
    }

    public static function ownershipStrictModeSafe(): bool
    {
        return (bool) config('bouncer.ownership.strict_mode_safe', true);
    }

    public static function displayPermissionInException(): bool
    {
        return (bool) config('bouncer.exceptions.display_permission_in_exception', false);
    }

    public static function displayRoleInException(): bool
    {
        return (bool) config('bouncer.exceptions.display_role_in_exception', false);
    }

    public static function registersOctaneResetListener(): bool
    {
        return (bool) config('bouncer.octane.register_reset_listener', true);
    }
}
