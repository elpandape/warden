<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class RemoteUser extends Model
{
    protected $connection = 'remote';

    protected $table = 'users';

    protected $fillable = ['name'];
}
