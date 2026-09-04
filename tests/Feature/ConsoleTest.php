<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\privateInstallPath;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('publishes config and migrations with warden:install', function (): void {
    // A private target keeps the shared skeleton untouched: parallel-safe.
    $dir = privateInstallPath($this->app);

    $this->artisan('warden:install')
        ->expectsOutputToContain('Warden is ready')
        ->assertExitCode(0);

    expect(file_exists($dir.'/warden.php'))->toBeTrue()
        ->and(glob($dir.'/migrations/*_create_warden_tables.php') ?: [])->toHaveCount(1);

    Illuminate\Support\Facades\File::deleteDirectory($dir);
});

it('resets the cache with warden:cache-reset', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();

    $this->artisan('warden:cache-reset')->assertExitCode(0);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('deletes unused permissions with warden:clean', function (): void {
    $this->warden->allow($this->user)->to('kept');
    Permission::query()->create(['name' => 'orphan-one']);
    Permission::query()->create(['name' => 'orphan-two']);

    $this->artisan('warden:clean --dry-run')
        ->expectsOutputToContain('Would delete 2')
        ->assertExitCode(0);

    expect(Permission::query()->count())->toBe(3);

    $this->artisan('warden:clean')
        ->expectsOutputToContain('Deleted 2')
        ->assertExitCode(0);

    expect(Permission::query()->pluck('name')->all())->toBe(['kept']);
});

it('shows the catalog with warden:show', function (): void {
    $this->warden->allow('admin')->to('audit', Account::class);
    $this->warden->allow($this->user)->toOwn(Account::class, 'edit');

    $this->artisan('warden:show')
        ->expectsOutputToContain('Roles')
        ->expectsOutputToContain('Permissions')
        ->assertExitCode(0);
});

it('shows one authority with warden:show Class:id', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->assign('editor')->on($org)->to($this->user);
    $this->warden->assign('admin')->to($this->user);
    $this->warden->allow($this->user)->to('edit-site');
    $this->warden->forbid($this->user)->to('delete');

    $this->artisan('warden:show', ['authority' => User::class.':'.$this->user->getKey()])
        ->expectsOutputToContain('Roles held by')
        ->expectsOutputToContain('Direct grants')
        ->assertExitCode(0);
});

it('rejects malformed authority references', function (): void {
    $this->artisan('warden:show', ['authority' => 'not-a-class'])
        ->assertExitCode(1);

    $this->artisan('warden:show', ['authority' => User::class.':999'])
        ->assertExitCode(1);
});

it('never publishes a duplicate migration on reinstall', function (): void {
    $dir = privateInstallPath($this->app);

    $this->artisan('warden:install')->assertExitCode(0);
    $this->artisan('warden:install')
        ->expectsOutputToContain('already published')
        ->assertExitCode(0);

    expect(glob($dir.'/migrations/*_create_warden_tables.php') ?: [])->toHaveCount(1);

    Illuminate\Support\Facades\File::deleteDirectory($dir);
});

it('leaves stranded grants alone unless asked to sweep them', function (): void {
    $role = Role::query()->create(['name' => 'gone']);
    app(Warden::class)->allow($role)->to('edit-site');

    Role::query()->whereKey($role->getKey())->getQuery()->delete();

    $stranded = fn (): int => Grant::query()->withoutGlobalScopes()
        ->where('entity_type', $role->getMorphClass())
        ->count();

    expect($stranded())->toBe(1);

    $this->artisan('warden:clean')->assertSuccessful();

    expect($stranded())->toBe(1);

    $this->artisan('warden:clean', ['--stranded' => true])->assertSuccessful();

    expect($stranded())->toBe(0);
});

it('reports a morph alias no class maps to instead of deleting its grants', function (): void {
    $role = Role::query()->create(['name' => 'gone']);
    app(Warden::class)->allow($role)->to('edit-site');

    Grant::query()->withoutGlobalScopes()->getQuery()->update(['entity_type' => 'nothing.maps.here']);

    $this->artisan('warden:clean', ['--stranded' => true])->assertSuccessful();

    expect(Grant::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('resolves duplicate catalog rows and re-points their grants', function (): void {
    $warden = app(Warden::class);
    $user = User::query()->create(['name' => 'Joseph']);
    $other = User::query()->create(['name' => 'Ana']);

    $warden->allow($user)->to('view');
    $keeper = Permission::query()->sole();

    // The duplicate the unique index now forbids, written the way an older
    // install already has it: straight past the model.
    $loserId = Permission::query()->getQuery()->insertGetId([
        'name' => 'view', 'entity_type' => null, 'entity_id' => null,
        'only_owned' => false, 'options' => null, 'scope' => null, 'identity_key' => 'stale',
    ]);
    Grant::query()->getQuery()->insert([
        'permission_id' => $loserId, 'entity_type' => $other->getMorphClass(),
        'entity_id' => $other->getKey(), 'forbidden' => false, 'scope' => null,
    ]);

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    expect(Permission::query()->count())->toBe(1)
        ->and(Grant::query()->where('permission_id', $keeper->getKey())->count())->toBe(2);
});

it('refuses to collapse a catalog row whose stored options do not decode', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->to('view', Account::class);
    $plain = Permission::query()->sole();

    $undecodableId = Permission::query()->getQuery()->insertGetId([
        'name' => $plain->getAttribute('name'),
        'entity_type' => $plain->getAttribute('entity_type'),
        'entity_id' => null,
        'only_owned' => false,
        'options' => '{"v":1,"g":',
        'scope' => null,
        'identity_key' => 'stale',
    ]);
    Grant::query()->getQuery()->insert([
        'permission_id' => $undecodableId, 'entity_type' => $other->getMorphClass(),
        'entity_id' => $other->getKey(), 'forbidden' => false, 'scope' => null,
    ]);

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    expect(Permission::query()->count())->toBe(2)
        ->and(Grant::query()->where('permission_id', $undecodableId)->count())->toBe(1)
        ->and(Grant::query()->where('permission_id', $plain->getKey())->count())->toBe(1);
});

it('leaves a lone catalog row alone when collapsing duplicates', function (): void {
    app(Warden::class)->allow(User::query()->create(['name' => 'Solo']))->to('view');

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    expect(Permission::query()->count())->toBe(1);
});
