<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\TestCase;

require_once __DIR__.'/Database/helpers.php';

pest()->extend(TestCase::class)->in(__DIR__);
