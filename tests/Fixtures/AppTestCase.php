<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Testing\WithPermissions;
use ElPandaPe\Bouncer\Tests\TestCase;

/**
 * How an app suite adopts the testing helpers.
 */
abstract class AppTestCase extends TestCase
{
    use WithPermissions;
}
