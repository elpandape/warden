<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('isolates grants between tenants', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    $this->bouncer->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('isolates role assignments between tenants', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow('admin')->to('scale');
    $this->bouncer->assign('admin')->to($this->user);

    expect($this->user->isA('admin'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('scale'))->toBeTrue();

    $this->bouncer->tenant()->to(2);

    expect($this->user->isA('admin'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('scale'))->toBeFalse();
});

it('keeps unscoped rows visible from every tenant', function (): void {
    $this->bouncer->allow($this->user)->to('browse');

    $this->bouncer->tenant()->to(7);

    expect(Gate::forUser($this->user)->allows('browse'))->toBeTrue();
});

it('sees everything without an active tenant by default', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');

    $this->bouncer->tenant()->remove();

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();
});

it('hides scoped rows without a tenant under strict null behavior', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->tenant()->remove();
    $this->bouncer->allowEveryone()->to('browse');

    config()->set('bouncer.scope.null_behavior', 'strict');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('browse'))->toBeTrue();
});

it('runs a callback under a temporary tenant and always restores', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');

    $inside = $this->bouncer->tenant()->onceTo(2, fn (): bool => Gate::forUser($this->user)->allows('edit-site'));

    expect($inside)->toBeFalse()
        ->and($this->bouncer->tenant()->current())->toBe(1);

    try {
        $this->bouncer->tenant()->onceTo(2, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // The temporary tenant must not leak past the exception.
    }

    expect($this->bouncer->tenant()->current())->toBe(1)
        ->and($this->bouncer->tenant()->removeOnce(fn () => $this->bouncer->tenant()->current()))->toBeNull();
});

it('keeps the catalog global with onlyRelations', function (): void {
    $this->bouncer->tenant()->onlyRelations()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');

    $this->bouncer->tenant()->to(2);

    expect(Permission::query()->where('name', 'edit-site')->exists())->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('keeps role grants global with dontScopeRoleGrants', function (): void {
    $this->bouncer->tenant()->dontScopeRoleGrants()->to(1);
    $this->bouncer->allow('admin')->to('audit');
    $this->bouncer->assign('admin')->to($this->user);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBeNull();

    // The role assignment stays scoped; the role's grant follows to any tenant it is held in.
    $this->bouncer->tenant()->to(1);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();
});

it('resolves the initial tenant from the configured resolver', function (): void {
    config()->set('bouncer.scope.tenant_resolver', ElPandaPe\Bouncer\Tests\Fixtures\FixedTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Bouncer\Tenancy\Tenancy::class);

    expect($this->bouncer->tenant()->current())->toBe(42)
        ->and($this->bouncer->scope())->toBe($this->bouncer->tenant());
});

it('filters the catalog itself under strict null behavior', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->tenant()->remove();

    config()->set('bouncer.scope.null_behavior', 'strict');

    // The scoped catalog row is invisible, not just its grants.
    expect(Permission::query()->where('name', 'edit-site')->exists())->toBeFalse();
});

it('keeps remove() authoritative over a configured resolver', function (): void {
    config()->set('bouncer.scope.tenant_resolver', ElPandaPe\Bouncer\Tests\Fixtures\FixedTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Bouncer\Tenancy\Tenancy::class);

    expect($this->bouncer->tenant()->current())->toBe(42);

    // An explicit remove() must not fall back to the resolver again.
    $this->bouncer->tenant()->remove();

    expect($this->bouncer->tenant()->current())->toBeNull();
});

it('memoizes the resolved tenant across reads', function (): void {
    ElPandaPe\Bouncer\Tests\Fixtures\CountingTenantResolver::$calls = 0;
    config()->set('bouncer.scope.tenant_resolver', ElPandaPe\Bouncer\Tests\Fixtures\CountingTenantResolver::class);
    app()->forgetInstance(ElPandaPe\Bouncer\Tenancy\Tenancy::class);

    $tenancy = $this->bouncer->tenant();

    expect($tenancy->current())->toBe(7)
        ->and($tenancy->current())->toBe(7)
        ->and(ElPandaPe\Bouncer\Tests\Fixtures\CountingTenantResolver::$calls)->toBe(1);
});

it('restores the no-tenant state after a temporary switch', function (): void {
    $inside = $this->bouncer->tenant()->onceTo(2, fn (): int|string|null => $this->bouncer->tenant()->current());

    expect($inside)->toBe(2)
        ->and($this->bouncer->tenant()->current())->toBeNull();

    // removeOnce from a no-tenant state must land back on no tenant too.
    $removed = $this->bouncer->tenant()->removeOnce(fn (): int|string|null => $this->bouncer->tenant()->current());

    expect($removed)->toBeNull()
        ->and($this->bouncer->tenant()->current())->toBeNull();
});
