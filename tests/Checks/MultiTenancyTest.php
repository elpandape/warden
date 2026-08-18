<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('isolates grants between tenants', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $this->warden->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('isolates role assignments between tenants', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow('admin')->to('scale');
    $this->warden->assign('admin')->to($this->user);

    expect($this->user->isA('admin'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('scale'))->toBeTrue();

    $this->warden->tenant()->to(2);

    expect($this->user->isA('admin'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('scale'))->toBeFalse();
});

it('keeps unscoped rows visible from every tenant', function (): void {
    $this->warden->allow($this->user)->to('browse');

    $this->warden->tenant()->to(7);

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue();
});

it('sees everything without an active tenant by default', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('edit-site');

    $this->warden->tenant()->remove();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('hides scoped rows without a tenant under strict null behavior', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->tenant()->remove();
    $this->warden->allowEveryone()->to('browse');

    config()->set('warden.scope.null_behavior', 'strict');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('browse'))->toBeTrue();
});

it('runs a callback under a temporary tenant and always restores', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('edit-site');

    $inside = $this->warden->tenant()->onceTo(2, fn (): bool => Gate::forUser($this->user)->allows('edit-site'));

    expect($inside)->toBeFalse()
        ->and($this->warden->tenant()->current())->toBe(1);

    try {
        $this->warden->tenant()->onceTo(2, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // The temporary tenant must not leak past the exception.
    }

    expect($this->warden->tenant()->current())->toBe(1)
        ->and($this->warden->tenant()->removeOnce(fn () => $this->warden->tenant()->current()))->toBeNull();
});

it('keeps the catalog global with onlyRelations', function (): void {
    $this->warden->tenant()->onlyRelations()->to(1);
    $this->warden->allow($this->user)->to('edit-site');

    $this->warden->tenant()->to(2);

    expect(Permission::query()->where('name', 'edit-site')->exists())->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('keeps role grants global with dontScopeRoleGrants', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);
    $this->warden->allow('admin')->to('audit');
    $this->warden->assign('admin')->to($this->user);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();

    // The role assignment stays scoped; the role's grant follows to any tenant it is held in.
    $this->warden->tenant()->to(1);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();
});

it('resolves the initial tenant from the configured resolver', function (): void {
    config()->set('warden.scope.tenant_resolver', ElPandaPe\Warden\Tests\Fixtures\FixedTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Warden\Tenancy\Tenancy::class);

    expect($this->warden->tenant()->current())->toBe(42)
        ->and($this->warden->scope())->toBe($this->warden->tenant());
});

it('filters the catalog itself under strict null behavior', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->tenant()->remove();

    config()->set('warden.scope.null_behavior', 'strict');

    // The scoped catalog row is invisible, not just its grants.
    expect(Permission::query()->where('name', 'edit-site')->exists())->toBeFalse();
});

it('keeps remove() authoritative over a configured resolver', function (): void {
    config()->set('warden.scope.tenant_resolver', ElPandaPe\Warden\Tests\Fixtures\FixedTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Warden\Tenancy\Tenancy::class);

    expect($this->warden->tenant()->current())->toBe(42);

    // An explicit remove() must not fall back to the resolver again.
    $this->warden->tenant()->remove();

    expect($this->warden->tenant()->current())->toBeNull();
});

it('memoizes the resolved tenant across reads', function (): void {
    ElPandaPe\Warden\Tests\Fixtures\CountingTenantResolver::$calls = 0;
    config()->set('warden.scope.tenant_resolver', ElPandaPe\Warden\Tests\Fixtures\CountingTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Warden\Tenancy\Tenancy::class);

    $tenancy = $this->warden->tenant();

    expect($tenancy->current())->toBe(7)
        ->and($tenancy->current())->toBe(7)
        ->and(ElPandaPe\Warden\Tests\Fixtures\CountingTenantResolver::$calls)->toBe(1);
});

it('restores the no-tenant state after a temporary switch', function (): void {
    $inside = $this->warden->tenant()->onceTo(2, fn (): int|string|null => $this->warden->tenant()->current());

    expect($inside)->toBe(2)
        ->and($this->warden->tenant()->current())->toBeNull();

    // removeOnce from a no-tenant state must land back on no tenant too.
    $removed = $this->warden->tenant()->removeOnce(fn (): int|string|null => $this->warden->tenant()->current());

    expect($removed)->toBeNull()
        ->and($this->warden->tenant()->current())->toBeNull();
});
