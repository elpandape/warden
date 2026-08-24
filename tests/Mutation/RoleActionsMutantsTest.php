<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\NarrowSignatureAuthority;
use ElPandaPe\Warden\Tests\Fixtures\RoleName;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    // The tests that need the cached resolver enable it themselves.
    config()->set('warden.cache.enabled', false);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('creates the role-name authority when syncing roles', function (): void {
    $this->warden->sync('brand-new-role')->roles([]);

    expect(Role::query()->where('name', 'brand-new-role')->exists())->toBeTrue();
});

it('keeps existing assignment rows untouched when syncing the same roles', function (): void {
    $this->warden->assign('editor')->to($this->user);

    $originalId = AssignedRole::query()->sole()->getAttribute('id');

    $this->warden->sync($this->user)->roles(['editor']);

    expect(AssignedRole::query()->sole()->getAttribute('id'))->toBe($originalId)
        ->and($this->user->isA('editor'))->toBeTrue();
});

it('invalidates cached checks when syncing roles to an empty set', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->assign('editor')->to($this->user);
    $this->warden->allow('editor')->to('audit');

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();

    $this->warden->sync($this->user)->roles([]);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();
});

it('invalidates cached checks when assigning a role', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->allow('editor')->to('audit');

    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();

    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();
});

it('stores one assignment row per tenant for the same role', function (): void {
    $this->warden->tenant()->onlyRelations()->to(1);
    $this->warden->assign('editor')->to($this->user);

    $this->warden->tenant()->to(2);
    $this->warden->assign('editor')->to($this->user);

    expect(AssignedRole::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($this->user->isA('editor'))->toBeTrue();
});

it('scopes a user permission sync to the tenant even with global role grants', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->sync($this->user)->permissions(['edit']);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBe(1);
});

it('forbids a permission through sync despite an existing allow grant', function (): void {
    $this->warden->allow($this->user)->to('comment');

    $this->warden->sync($this->user)->forbiddenPermissions(['comment']);

    expect(Gate::forUser($this->user)->allows('comment'))->toBeFalse();
});

it('hands isA plain names when checking with enums', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->a(RoleName::Editor))->toBeTrue();
});

it('hands isA positional names when spread with string keys', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->a(...['named' => 'editor']))->toBeTrue();
});

it('hands isAll plain names when checking with enums', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->all(RoleName::Editor))->toBeTrue();
});

it('hands isAll positional names when spread with string keys', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->all(...['named' => 'editor']))->toBeTrue();
});
