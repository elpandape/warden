<?php

declare(strict_types=1);

use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\RoleName;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

/**
 * An authority with its own name-based role checks: the ChecksRoles action
 * promises normalized, positional role names to every concern user, including
 * ones that override isA()/isAll() with string-only signatures.
 */
final class NarrowSignatureAuthority extends Model
{
    use HasRolesAndPermissions;

    public function isA(string $primary = '', string ...$others): bool
    {
        return $primary === 'editor';
    }

    public function isAll(string $primary = '', string ...$others): bool
    {
        return $primary === 'editor';
    }
}

beforeEach(function (): void {
    migrateWardenTables();

    // Cache-version mutants opt into the cached resolver per test.
    config()->set('warden.cache.enabled', false);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

// Kills 02b7ff25e1ddde45 (SyncsRolesAndPermissions::roles, TrueToFalse on
// createRole): a string authority names a role that syncing creates on the
// fly; the mutant throws RoleDoesNotExist instead.
it('creates the role-name authority when syncing roles', function (): void {
    $this->warden->sync('brand-new-role')->roles([]);

    expect(Role::query()->where('name', 'brand-new-role')->exists())->toBeTrue();
});

// Kills 581fcf63afcf598a (SyncsRolesAndPermissions::roles, UnwrapArrayMap on
// the kept keys): the whereNotIn guard must compare role keys, not models —
// otherwise every kept assignment is deleted and recreated as a new row.
it('keeps existing assignment rows untouched when syncing the same roles', function (): void {
    $this->warden->assign('editor')->to($this->user);

    $originalId = AssignedRole::query()->sole()->getAttribute('id');

    $this->warden->sync($this->user)->roles(['editor']);

    expect(AssignedRole::query()->sole()->getAttribute('id'))->toBe($originalId)
        ->and($this->user->isA('editor'))->toBeTrue();
});

// Kills 9aff20026d0981ca (SyncsRolesAndPermissions::roles, RemoveMethodCall
// on bumpCacheVersion): syncing down to zero roles must invalidate cached
// checks even though the assign step — the other bump — never runs.
it('invalidates cached checks when syncing roles to an empty set', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->assign('editor')->to($this->user);
    $this->warden->allow('editor')->to('audit');

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();

    $this->warden->sync($this->user)->roles([]);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();
});

// Kills b7224412a748377a (AssignsRoles::to, RemoveMethodCall on
// bumpCacheVersion): assigning a role must invalidate the authority's
// previously cached denial.
it('invalidates cached checks when assigning a role', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->allow('editor')->to('audit');

    expect(Gate::forUser($this->user)->allows('audit'))->toBeFalse();

    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();
});

// Kills 00c733ad8b15696b (AssignsRoles::to, RemoveArrayItem on 'scope'): the
// same role assigned under two tenants needs one row per tenant; without the
// scope attribute the second firstOrCreate matches the first tenant's row.
it('stores one assignment row per tenant for the same role', function (): void {
    $this->warden->tenant()->onlyRelations()->to(1);
    $this->warden->assign('editor')->to($this->user);

    $this->warden->tenant()->to(2);
    $this->warden->assign('editor')->to($this->user);

    expect(AssignedRole::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($this->user->isA('editor'))->toBeTrue();
});

// Kills adc345641f41a52a (SyncsRolesAndPermissions::syncGrants,
// InstanceOfToTrue on the authority): only role authorities may keep their
// grants global; a user's synced permissions stay scoped to the tenant.
it('scopes a user permission sync to the tenant even with global role grants', function (): void {
    $this->warden->tenant()->dontScopeRoleGrants()->to(1);

    $this->warden->sync($this->user)->permissions(['edit']);

    expect(Grant::query()->withoutGlobalScopes()->sole()->getAttribute('scope'))->toBe(1);
});

// Kills 8983c12a78c7fc08 (SyncsRolesAndPermissions::syncGrants,
// RemoveArrayItem on 'forbidden'): syncing forbidden permissions must create
// the forbidden row even when an identical allow row already exists — the
// mutant's firstOrCreate matches the allow row and creates nothing.
it('forbids a permission through sync despite an existing allow grant', function (): void {
    $this->warden->allow($this->user)->to('comment');

    $this->warden->sync($this->user)->forbiddenPermissions(['comment']);

    expect(Gate::forUser($this->user)->allows('comment'))->toBeFalse();
});

// Kills aa3f0d185d1fc338 (ChecksRoles::a, UnwrapArrayMap): enum roles must
// reach the authority as plain names — an authority typed against strings
// gets a TypeError otherwise.
it('hands isA plain names when checking with enums', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->a(RoleName::Editor))->toBeTrue();
});

// Kills bd4d4b1e7db359b6 (ChecksRoles::a, UnwrapArrayValues): string keys
// survive array_map and would spread as named arguments into the authority;
// reindexing keeps every role positional.
it('hands isA positional names when spread with string keys', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->a(...['named' => 'editor']))->toBeTrue();
});

// Kills a6d17d83733cc09d (ChecksRoles::all, UnwrapArrayMap): the same enum
// normalization guarantee for isAll.
it('hands isAll plain names when checking with enums', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->all(RoleName::Editor))->toBeTrue();
});

// Kills 6fb1710c23f8e0f9 (ChecksRoles::all, UnwrapArrayValues): the same
// positional-spread guarantee for isAll.
it('hands isAll positional names when spread with string keys', function (): void {
    $checks = $this->warden->is(new NarrowSignatureAuthority);

    expect($checks->all(...['named' => 'editor']))->toBeTrue();
});
