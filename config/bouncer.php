<?php

declare(strict_types=1);

return [

    /*
     * Model overrides. Null means the package default.
     */
    'models' => [
        'permission' => null,
        'role' => null,
        'assigned_role' => null,
        'grant' => null,
        // Null resolves the user model from the default auth guard.
        'user' => null,
    ],

    /*
     * Pivot timestamps are opt-in: uncomment the timestamp columns in the
     * published migration before enabling this.
     */
    'pivot_timestamps' => false,

    'tables' => [
        'permissions' => 'permissions',
        'roles' => 'roles',
        'assigned_roles' => 'assigned_roles',
        'grants' => 'grants',
    ],

    /*
     * Dedicated database connection (multi-database tenancy). Null uses the default.
     */
    'connection' => null,

    /*
     * Stable morph aliases, compatible with Relation::enforceMorphMap().
     */
    'morph_aliases' => [
        'permission' => 'bouncer.permission',
        'role' => 'bouncer.role',
    ],

    'gate' => [
        // Set to false to register your own Gate callback instead.
        'register' => true,
        'run_before_policies' => false,
    ],

    'ownership' => [
        'default_attribute' => 'user_id',
        // Never throw under Model::shouldBeStrict() when the attribute is missing.
        'strict_mode_safe' => true,
    ],

    'scope' => [
        // 'all': rows without an active scope see every tenant. 'strict': only NULL-scoped rows.
        'null_behavior' => 'all',
        'column_type' => 'integer',
        // A class implementing Contracts\TenantResolver to detect the active tenant.
        'tenant_resolver' => null,
        // Keep the role/permission catalog global; scope only the pivots.
        'only_relations' => false,
        // Set to false to keep grants held by roles global across tenants.
        'role_grants' => true,
    ],

    'cache' => [
        'enabled' => true,
        'store' => 'default',
        'prefix' => 'bouncer',
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
    ],

    'events_enabled' => true,

    'titles' => [
        'autogenerate' => true,
    ],

    'exceptions' => [
        // Keep both false to avoid leaking permission/role names in 403 messages.
        'display_permission_in_exception' => false,
        'display_role_in_exception' => false,
    ],

    'octane' => [
        'register_reset_listener' => true,
    ],

    /*
     * Optional extras, off by default.
     */
    'register_middleware_aliases' => false,
    'register_blade_directives' => false,

];
