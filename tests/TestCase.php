<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests;

use ElPandaPe\Bouncer\BouncerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BouncerServiceProvider::class];
    }
}
