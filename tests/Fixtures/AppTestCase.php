<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Testing\WithPermissions;
use ElPandaPe\Warden\Tests\TestCase;

/**
 * How an app suite adopts the testing helpers.
 */
abstract class AppTestCase extends TestCase
{
    use WithPermissions;
}
