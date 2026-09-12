<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\RemoteTextKeyUser;
use ElPandaPe\Warden\Tests\Fixtures\RemoteUser;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateRemoteUsers;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;
use function ElPandaPe\Warden\Tests\nestRole;
use function ElPandaPe\Warden\Tests\plantDuplicateViewGrant;
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

    plantDuplicateViewGrant($other);

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
        'options' => 'null',
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

it('deletes rows past their end date from both pivots', function (): void {
    $warden = app(Warden::class);
    $user = User::query()->create(['name' => 'Ada']);
    $past = Carbon::now()->subDay();

    $warden->allow($user)->until($past)->to('view', Account::class);
    $warden->assign('auditor')->until($past)->to($user);
    $warden->allow($user)->to('browse');

    $this->artisan('warden:clean --expired')
        ->expectsOutputToContain('Deleted 2 expired row(s).')
        ->assertExitCode(0);

    expect(Grant::query()->count())->toBe(1)
        ->and(AssignedRole::query()->count())->toBe(0);
});

it('counts expired rows without deleting them on a dry run', function (): void {
    $warden = app(Warden::class);
    $user = User::query()->create(['name' => 'Ada']);

    $warden->allow($user)->until(Carbon::now()->subDay())->to('view', Account::class);

    $this->artisan('warden:clean --expired --dry-run')
        ->expectsOutputToContain('Would delete 1 expired row(s).')
        ->assertExitCode(0);

    expect(Grant::query()->count())->toBe(1);
});

it('stops authorizing before anyone runs the sweep', function (): void {
    $warden = app(Warden::class);
    $user = User::query()->create(['name' => 'Ada']);
    $account = Account::query()->create(['name' => 'Acme']);

    $warden->allow($user)->until(Carbon::now()->subDay())->to('view', Account::class);

    expect(Gate::forUser($user)->allows('view', $account))->toBeFalse()
        ->and(Grant::query()->count())->toBe(1);
});

it('sweeps a nesting edge whose parent role is gone', function (): void {
    $warden = app(Warden::class);
    $user = User::query()->create(['name' => 'Ada']);

    nestRole('auditor', 'editor');
    $warden->assign('editor')->to($user);

    Role::query()->where('name', 'editor')->delete();

    $this->artisan('warden:clean --stranded')
        ->expectsOutputToContain('Deleted 1 stranded row(s).')
        ->assertExitCode(0);

    // The edge pointing AT the deleted role is the one no foreign key reaches.
    expect(AssignedRole::query()->where('entity_type', 'warden.role')->count())->toBe(0);
});

it('sweeps stranded rows against the connection their authority model lives on', function (): void {
    migrateRemoteUsers();

    $ana = RemoteUser::query()->forceCreate(['id' => 41, 'name' => 'Ana']);
    $luis = RemoteUser::query()->forceCreate(['id' => 42, 'name' => 'Luis']);

    $this->warden->allow($ana)->to('view');
    $this->warden->allow($luis)->to('view');

    Grant::query()->getQuery()->insert([
        'permission_id' => Permission::query()->sole()->getKey(), 'entity_type' => $ana->getMorphClass(),
        'entity_id' => null, 'forbidden' => false, 'scope' => null,
    ]);

    $luis->delete();

    $this->artisan('warden:clean', ['--stranded' => true])
        ->expectsOutputToContain('Deleted 2 stranded row(s).')
        ->assertSuccessful();

    expect(Grant::query()->pluck('entity_id')->all())->toBe([41]);
});

it('keeps every grant whose authority exists on its own connection', function (): void {
    migrateRemoteUsers();

    $this->warden->allow(RemoteUser::query()->forceCreate(['id' => 41, 'name' => 'Ana']))->to('view');

    $this->artisan('warden:clean', ['--stranded' => true])
        ->expectsOutputToContain('Deleted 0 stranded row(s).')
        ->assertSuccessful();

    expect(Grant::query()->count())->toBe(1);
});

it('keeps a grant whose holder its own database matches under its collation', function (): void {
    migrateRemoteUsers(textKeyCollation: 'RTRIM');

    RemoteTextKeyUser::query()->getQuery()->insert(['id' => '41 ', 'name' => 'Ana']);

    $this->warden->allow($this->user)->to('view');

    Grant::query()->getQuery()->insert([
        'permission_id' => Permission::query()->sole()->getKey(), 'entity_type' => (new RemoteTextKeyUser)->getMorphClass(),
        'entity_id' => 41, 'forbidden' => false, 'scope' => null,
    ]);

    $this->artisan('warden:clean', ['--stranded' => true])
        ->expectsOutputToContain('Deleted 0 stranded row(s).')
        ->assertSuccessful();

    expect(Grant::query()->where('entity_type', (new RemoteTextKeyUser)->getMorphClass())->pluck('entity_id')->all())->toBe([41]);
});

it('keeps access alive when the duplicate grant outlives the keeper\'s', function (): void {
    withForeignKeys();

    $this->warden->allow($this->user)->until(Carbon::now()->addDay())->to('view');

    plantDuplicateViewGrant($this->user);

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    $this->travel(2)->days();

    expect(Gate::forUser($this->user)->allows('view'))->toBeTrue();
});

it('folds a colliding duplicate grant into the keeper\'s with the later end date', function (?int $keeperDays, ?int $loserDays, ?int $keptDays): void {
    withForeignKeys();
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $at = fn (?int $days): ?Carbon => $days === null ? null : Carbon::now()->addDays($days);

    $this->warden->allow($this->user)->until($at($keeperDays))->to('view');
    $keeper = Permission::query()->sole();

    plantDuplicateViewGrant($this->user, $at($loserDays));

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    $grant = Grant::query()->sole();

    expect($grant->getAttribute('permission_id'))->toBe($keeper->getKey())
        ->and($grant->expires_at?->toDateTimeString())->toBe($at($keptDays)?->toDateTimeString());
})->with([
    'the loser has no end' => [1, null, null],
    'the keeper has no end' => [null, 1, null],
    'the loser ends later' => [1, 3, 3],
    'the keeper ends later' => [3, 1, 3],
]);
