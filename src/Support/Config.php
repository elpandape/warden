<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use ElPandaPe\Warden\Enums\GateSlot;
use ElPandaPe\Warden\Exceptions\ConfigurationException;

final class Config
{
    public static function nestedRoles(): bool
    {
        return (bool) config('warden.roles.nested', false);
    }

    /**
     * A ceiling rather than a cycle exception: a cycle stops expanding instead
     * of throwing, because assigning a role to a role is accepted today and
     * turning that into a failure is a break nobody asked for.
     */
    public static function roleMaxDepth(): int
    {
        $depth = config('warden.roles.max_depth', 10);

        return is_int($depth) && $depth > 0 ? $depth : 10;
    }

    public static function eventsEnabled(): bool
    {
        return (bool) config('warden.events_enabled', true);
    }

    public static function cancellableEvents(): bool
    {
        return (bool) config('warden.cancellable_events', false);
    }

    public static function gateRegisters(): bool
    {
        return (bool) config('warden.gate.register', true);
    }

    public static function gateSlot(): GateSlot
    {
        return config('warden.gate.run_before_policies', false)
            ? GateSlot::Before
            : GateSlot::After;
    }

    public static function titlesAutogenerate(): bool
    {
        return (bool) config('warden.titles.autogenerate', true);
    }

    public static function pivotTimestamps(): bool
    {
        return (bool) config('warden.pivot_timestamps', false);
    }

    public static function scopeNullBehavior(): string
    {
        $value = config('warden.scope.null_behavior', 'all');

        // Anything unrecognised used to read as 'all', which is the widening
        // direction: a typo silently opened reads across every tenant.
        if ($value !== 'all' && $value !== 'strict') {
            throw new ConfigurationException(
                'Unknown warden.scope.null_behavior ['.get_debug_type($value)."]: expected 'all' or 'strict'.",
            );
        }

        return $value;
    }

    public static function ownershipStrictModeSafe(): bool
    {
        return (bool) config('warden.ownership.strict_mode_safe', true);
    }

    public static function displayPermissionInException(): bool
    {
        return (bool) config('warden.exceptions.display_permission_in_exception', false);
    }

    public static function displayRoleInException(): bool
    {
        return (bool) config('warden.exceptions.display_role_in_exception', false);
    }

    public static function registersMiddlewareAliases(): bool
    {
        return (bool) config('warden.register_middleware_aliases', false);
    }

    public static function registersBladeDirectives(): bool
    {
        return (bool) config('warden.register_blade_directives', false);
    }

    public static function registersOctaneResetListener(): bool
    {
        return (bool) config('warden.octane.register_reset_listener', true);
    }

    public static function scopeOnlyRelations(): bool
    {
        return (bool) config('warden.scope.only_relations', false);
    }

    public static function scopeRoleGrants(): bool
    {
        return (bool) config('warden.scope.role_grants', true);
    }

    public static function restrictionsDefaultAttribute(): ?string
    {
        $attribute = config('warden.restrictions.default_attribute');

        return is_string($attribute) && $attribute !== '' ? $attribute : null;
    }

    public static function cacheEnabled(): bool
    {
        return (bool) config('warden.cache.enabled', true);
    }

    public static function cacheStore(): ?string
    {
        $store = config('warden.cache.store', 'default');

        return is_string($store) && $store !== 'default' ? $store : null;
    }

    public static function cachePrefix(): string
    {
        $prefix = config('warden.cache.prefix', 'warden');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'warden';
    }

    public static function cacheTtl(): \DateInterval|int|null
    {
        $ttl = config('warden.cache.expiration_time');

        return $ttl instanceof \DateInterval || is_int($ttl) ? $ttl : null;
    }
}
