<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

/**
 * Point config/database publish targets at a throwaway directory.
 */
function privateInstallPath(Illuminate\Foundation\Application $app): string
{
    $dir = sys_get_temp_dir().'/warden-install-'.getmypid().'-'.uniqid();
    mkdir($dir.'/migrations', recursive: true);
    $app->useConfigPath($dir);
    $app->useDatabasePath($dir);

    // publishes() resolved absolute targets at boot: re-register them.
    new ElPandaPe\Warden\WardenServiceProvider($app)->boot();

    return $dir;
}

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
