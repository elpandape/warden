<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests;

use DateTimeInterface;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A rule the write path refuses since 3.0, planted the way a 2.x database left
 * it: straight into the column, without going through the fluent API.
 */
function storeConditionRefusedSince3(string $column = 'user_id'): void
{
    DB::table('permissions')->whereNotNull('entity_type')->update([
        'options' => '{"v": 1, "g": {"t": "group", "i": [["and", {"t": "value", "c": "'.$column.'", "o": "=", "v": true}]]}}',
    ]);
}

/**
 * Nest one role inside another: the edge is an assignment whose authority is
 * the outer role.
 */
function nestRole(string $inner, string $outer): void
{
    app(Warden::class)->assign($inner)->to(Role::query()->firstOrCreate(['name' => $outer]));
}

function projectIn(Account $org): Account
{
    return Account::query()->create(['name' => 'Project', 'account_id' => $org->getKey()])->refresh();
}

function requestAs(?User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

function cachedPayloadKey(User $authority): string
{
    return implode(':', [
        'warden',
        'p5',
        app(CacheKeyVersioner::class)->segment(),
        $authority->getMorphClass(),
        (string) $authority->getKey(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grantTuple(array $overrides = []): array
{
    return array_merge([
        'key' => 1,
        'name' => 'edit',
        'entity_type' => null,
        'entity_id' => null,
        'only_owned' => false,
        'forbidden' => false,
        'options' => null,
        'restricted_to_type' => null,
        'restricted_to_id' => null,
        'expires_at' => null,
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $grants
 */
function seedCachedPayload(User $authority, array $grants): void
{
    Cache::store('array')->put(cachedPayloadKey($authority), ['v' => 5, 'grants' => $grants], 60);
}

/**
 * Point config/database publish targets at a throwaway directory.
 */
function privateInstallPath(Application $app): string
{
    $dir = sys_get_temp_dir().'/warden-install-'.getmypid().'-'.uniqid();
    mkdir($dir.'/migrations', recursive: true);
    $app->useConfigPath($dir);
    $app->useDatabasePath($dir);

    // publishes() resolved absolute targets at boot: re-register them.
    new WardenServiceProvider($app)->boot();

    return $dir;
}

/**
 * Files whose contents match a pattern, for the architectural comment rules.
 *
 * @return list<string>
 */
function phpFilesOffending(string $pattern): array
{
    $offenders = [];

    foreach (['src', 'tests'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../'.$dir));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (is_string($contents) && preg_match($pattern, $contents) === 1) {
                $offenders[] = $file->getFilename();
            }
        }
    }

    return $offenders;
}

/**
 * @return list<int|string|null>
 */
function assignedRoleScopes(): array
{
    return AssignedRole::query()
        ->withoutGlobalScopes()
        ->orderByRaw('scope is null desc, scope asc')
        ->pluck('scope')
        ->all();
}

/**
 * The duplicate the unique index now forbids, granted the way an older
 * install already has it: straight past the model.
 */
function plantDuplicateViewGrant(User $holder, ?DateTimeInterface $expiresAt = null): void
{
    $duplicateId = Permission::query()->getQuery()->insertGetId([
        'name' => 'view', 'entity_type' => null, 'entity_id' => null,
        'only_owned' => false, 'options' => null, 'scope' => null, 'identity_key' => 'stale',
    ]);
    Grant::query()->getQuery()->insert([
        'permission_id' => $duplicateId, 'entity_type' => $holder->getMorphClass(),
        'entity_id' => $holder->getKey(), 'forbidden' => false, 'scope' => null, 'expires_at' => $expiresAt,
    ]);
}
