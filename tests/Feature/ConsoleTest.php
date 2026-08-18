<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('publishes config and migrations with bouncer:install', function (): void {
    $this->artisan('bouncer:install')
        ->expectsOutputToContain('Bouncer is ready')
        ->assertExitCode(0);

    // Leave the shared skeleton clean for every other test run.
    foreach (glob(database_path('migrations/*create_bouncer_tables.php')) ?: [] as $published) {
        unlink($published);
    }

    if (file_exists(config_path('bouncer.php'))) {
        unlink(config_path('bouncer.php'));
    }
});

it('resets the cache with bouncer:cache-reset', function (): void {
    config()->set('bouncer.cache.enabled', true);
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue();

    Grant::query()->withoutGlobalScopes()->delete();

    $this->artisan('bouncer:cache-reset')->assertExitCode(0);

    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeFalse();
});

it('deletes unused permissions with bouncer:clean', function (): void {
    $this->bouncer->allow($this->user)->to('kept');
    Permission::query()->create(['name' => 'orphan-one']);
    Permission::query()->create(['name' => 'orphan-two']);

    $this->artisan('bouncer:clean --dry-run')
        ->expectsOutputToContain('Would delete 2')
        ->assertExitCode(0);

    expect(Permission::query()->count())->toBe(3);

    $this->artisan('bouncer:clean')
        ->expectsOutputToContain('Deleted 2')
        ->assertExitCode(0);

    expect(Permission::query()->pluck('name')->all())->toBe(['kept']);
});

it('shows the catalog with bouncer:show', function (): void {
    $this->bouncer->allow('admin')->to('audit', Account::class);
    $this->bouncer->allow($this->user)->toOwn(Account::class, 'edit');

    $this->artisan('bouncer:show')
        ->expectsOutputToContain('Roles')
        ->expectsOutputToContain('Permissions')
        ->assertExitCode(0);
});

it('shows one authority with bouncer:show Class:id', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->bouncer->assign('editor')->on($org)->to($this->user);
    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->allow($this->user)->to('edit-site');
    $this->bouncer->forbid($this->user)->to('delete');

    $this->artisan('bouncer:show', ['authority' => User::class.':'.$this->user->getKey()])
        ->expectsOutputToContain('Roles held by')
        ->expectsOutputToContain('Direct grants')
        ->assertExitCode(0);
});

it('rejects malformed authority references', function (): void {
    $this->artisan('bouncer:show', ['authority' => 'not-a-class'])
        ->assertExitCode(1);

    $this->artisan('bouncer:show', ['authority' => User::class.':999'])
        ->assertExitCode(1);
});

it('never publishes a duplicate migration on reinstall', function (): void {
    $this->artisan('bouncer:install')->assertExitCode(0);
    $this->artisan('bouncer:install')
        ->expectsOutputToContain('already published')
        ->assertExitCode(0);

    expect(glob(database_path('migrations/*_create_bouncer_tables.php')) ?: [])->toHaveCount(1);

    foreach (glob(database_path('migrations/*create_bouncer_tables.php')) ?: [] as $published) {
        unlink($published);
    }

    if (file_exists(config_path('bouncer.php'))) {
        unlink(config_path('bouncer.php'));
    }
});
