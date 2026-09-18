<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

final class SoftDeletingUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}
