<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

final class StringKeyUser extends User
{
    protected $table = 'users';

    protected $keyType = 'string';
}
