<?php

declare(strict_types=1);

use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use ElPandaPe\Warden\Support\Titles\RoleTitle;

it('lists the current title first and the older one after it', function (): void {
    expect(PermissionTitle::generations('viewAny', 'App\\Models\\Post', null, false))
        ->toBe(['View any posts', 'ViewAny posts']);
});

it('lists the reading 2.0.0 gave a namespaced name', function (): void {
    expect(PermissionTitle::generations('page:App\\Filament\\Pages\\Settings', null, null, false))
        ->toBe(['Page:App\\Filament\\Pages\\Settings', 'Page: app\\ filament\\ pages\\ settings']);
});

it('collapses generations that agree', function (): void {
    expect(PermissionTitle::generations('ban-users', null, null, false))->toBe(['Ban users']);
});

it('reconstructs every shape a permission title can take', function (): void {
    expect(PermissionTitle::generations('*', '*', null, true))->toBe(['Manage everything owned'])
        ->and(PermissionTitle::generations('*', '*', null, false))->toBe(['All permissions'])
        ->and(PermissionTitle::generations('*', null, null, false))->toBe(['All simple permissions'])
        ->and(PermissionTitle::generations('editAll', '*', null, true))->toBe(['Edit all everything owned', 'EditAll everything owned'])
        ->and(PermissionTitle::generations('editAll', '*', null, false))->toBe(['Edit all everything', 'EditAll everything'])
        ->and(PermissionTitle::generations('editAll', 'App\\Models\\Post', 7, false))->toBe(['Edit all post #7', 'EditAll post #7'])
        ->and(PermissionTitle::generations('*', 'App\\Models\\Post', null, false))->toBe(['Manage posts']);
});

it('lists every title warden could have written for a role', function (): void {
    expect(RoleTitle::generations('siteAdmin'))->toBe(['Site admin', 'SiteAdmin'])
        ->and(RoleTitle::generations('site-admin'))->toBe(['Site admin'])
        ->and(RoleTitle::generations('tenant:Acme\\Team'))->toBe(['Tenant:Acme\\Team', 'Tenant: acme\\ team']);
});
