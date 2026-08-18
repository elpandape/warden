<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

afterEach(function (): void {
    // Leave no legacy tables behind for the rest of the suite.
    foreach (['abilities', 'abilities_gone'] as $table) {
        Schema::dropIfExists($table);
    }
});

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);

    // Replace this package's tables with a faithful silber/bouncer schema.
    // Real engines persist between tests: leftovers must go first.
    foreach (['grants', 'assigned_roles', 'roles', 'permissions', 'abilities', 'abilities_gone'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('abilities', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('title')->nullable();
        $table->unsignedBigInteger('entity_id')->nullable();
        $table->string('entity_type')->nullable();
        $table->boolean('only_owned')->default(false);
        $table->json('options')->nullable();
        $table->integer('scope')->nullable();
        $table->timestamps();
    });

    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('title')->nullable();
        $table->integer('scope')->nullable();
        $table->timestamps();
    });

    Schema::create('assigned_roles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('entity_id');
        $table->string('entity_type');
        $table->unsignedBigInteger('restricted_to_id')->nullable();
        $table->string('restricted_to_type')->nullable();
        $table->integer('scope')->nullable();
    });

    Schema::create('permissions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('ability_id');
        $table->unsignedBigInteger('entity_id')->nullable();
        $table->string('entity_type')->nullable();
        $table->boolean('forbidden')->default(false);
        $table->integer('scope')->nullable();
    });

    // Seed: a direct grant, a role grant under the legacy morph, a forbid,
    // and a constraint blob the original never evaluated.
    DB::table('abilities')->insert([
        ['id' => 1, 'name' => 'edit-site', 'title' => null, 'entity_id' => null, 'entity_type' => null, 'only_owned' => false, 'options' => null, 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'audit', 'title' => null, 'entity_id' => null, 'entity_type' => null, 'only_owned' => false, 'options' => json_encode(['class' => 'Silber\Bouncer\Constraints\Group']), 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'publish', 'title' => null, 'entity_id' => null, 'entity_type' => null, 'only_owned' => false, 'options' => null, 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('roles')->insert(['id' => 5, 'name' => 'admin', 'title' => null, 'scope' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('assigned_roles')->insert(['role_id' => 5, 'entity_id' => $this->user->getKey(), 'entity_type' => $this->user->getMorphClass(), 'restricted_to_id' => null, 'restricted_to_type' => null, 'scope' => null]);
    DB::table('permissions')->insert([
        ['ability_id' => 1, 'entity_id' => $this->user->getKey(), 'entity_type' => $this->user->getMorphClass(), 'forbidden' => false, 'scope' => null],
        ['ability_id' => 2, 'entity_id' => 5, 'entity_type' => 'Silber\Bouncer\Database\Role', 'forbidden' => false, 'scope' => null],
        ['ability_id' => 3, 'entity_id' => $this->user->getKey(), 'entity_type' => $this->user->getMorphClass(), 'forbidden' => true, 'scope' => null],
    ]);
});

it('reports without touching anything on a dry run', function (): void {
    $this->artisan('warden:upgrade --dry-run')
        ->expectsOutputToContain('silber/bouncer schema detected')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(Schema::hasTable('abilities'))->toBeTrue()
        ->and(Schema::hasTable('grants'))->toBeFalse();
});

it('upgrades the schema in place and keeps every verdict', function (): void {
    $this->artisan('warden:upgrade')->assertExitCode(0);

    expect(Schema::hasTable('grants'))->toBeTrue()
        ->and(Schema::hasTable('abilities'))->toBeFalse()
        ->and(Schema::hasColumn('grants', 'permission_id'))->toBeTrue();

    // Direct grant survives; the role grant survives the morph rewrite;
    // the explicit forbid still wins; the legacy blob is gone.
    expect(Gate::forUser($this->user)->allows('edit-site'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('audit'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and(DB::table('permissions')->whereNotNull('options')->count())->toBe(0);
});

it('rewrites custom legacy role morphs when told to', function (): void {
    DB::table('permissions')->where('ability_id', 2)->update(['entity_type' => 'App\Models\Role']);

    $this->artisan('warden:upgrade', ['--role-morph' => ['App\Models\Role']])->assertExitCode(0);

    expect(Gate::forUser($this->user)->allows('audit'))->toBeTrue();
});

it('refuses to run without a legacy schema or over a fresh install', function (): void {
    Schema::rename('abilities', 'abilities_gone');

    $this->artisan('warden:upgrade')->assertExitCode(1);

    Schema::rename('abilities_gone', 'abilities');
    Schema::create('grants', function (Blueprint $table): void {
        $table->id();
    });

    $this->artisan('warden:upgrade')->assertExitCode(1);
});

it('rewrites role morphs on role-targeted abilities too', function (): void {
    DB::table('abilities')->insert(['id' => 9, 'name' => 'manage', 'title' => null, 'entity_id' => null, 'entity_type' => 'roles', 'only_owned' => false, 'options' => null, 'scope' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('permissions')->insert(['ability_id' => 9, 'entity_id' => $this->user->getKey(), 'entity_type' => $this->user->getMorphClass(), 'forbidden' => false, 'scope' => null]);

    $this->artisan('warden:upgrade')->assertExitCode(0);

    expect(Gate::forUser($this->user)->allows('manage', ElPandaPe\Warden\Models\Role::class))->toBeTrue();
});

it('refuses to widen legacy tenant isolation under the all default', function (): void {
    DB::table('permissions')->where('ability_id', 1)->update(['scope' => 2]);

    $this->artisan('warden:upgrade')
        ->expectsOutputToContain('null_behavior')
        ->assertExitCode(1);

    expect(Schema::hasTable('abilities'))->toBeTrue();

    // Explicit acknowledgement, or the legacy-faithful strict mode, proceed.
    config()->set('warden.scope.null_behavior', 'strict');

    $this->artisan('warden:upgrade')->assertExitCode(0);
});

it('proceeds over scoped rows when widening is acknowledged', function (): void {
    DB::table('permissions')->where('ability_id', 1)->update(['scope' => 2]);

    $this->artisan('warden:upgrade', ['--allow-open-scopes' => true])->assertExitCode(0);
});
