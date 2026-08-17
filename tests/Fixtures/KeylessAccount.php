<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

final class KeylessAccount extends Account
{
    protected $table = 'accounts';

    public function getKey(): mixed
    {
        return null;
    }
}
