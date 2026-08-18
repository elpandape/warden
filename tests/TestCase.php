<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests;

use ElPandaPe\Warden\WardenServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        // Real database engines cap connections; leaked PDO handles add up
        // across a 260-test run, so close them eagerly.
        $this->app['db']->disconnect();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [WardenServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // The default pass runs the database engine; WARDEN_TEST_RESOLVER=cached
        // re-runs the whole suite through the cached resolver (parity matrix).
        $app['config']->set('warden.cache.enabled', getenv('WARDEN_TEST_RESOLVER') === 'cached');
    }
}
