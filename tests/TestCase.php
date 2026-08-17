<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests;

use ElPandaPe\Bouncer\BouncerServiceProvider;
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
        return [BouncerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // The default pass runs the database engine; BOUNCER_TEST_RESOLVER=cached
        // re-runs the whole suite through the cached resolver (parity matrix).
        $app['config']->set('bouncer.cache.enabled', getenv('BOUNCER_TEST_RESOLVER') === 'cached');
    }
}
