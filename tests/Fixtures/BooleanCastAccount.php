<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

final class BooleanCastAccount extends Account
{
    protected $table = 'accounts';

    protected $casts = ['user_id' => 'boolean'];
}
