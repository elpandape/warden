<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests;

use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        'p2',
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
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $grants
 */
function seedCachedPayload(User $authority, array $grants): void
{
    Cache::store('array')->put(cachedPayloadKey($authority), ['v' => 2, 'grants' => $grants], 60);
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
