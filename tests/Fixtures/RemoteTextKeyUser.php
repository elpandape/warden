<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class RemoteTextKeyUser extends Model
{
    public $incrementing = false;

    protected $connection = 'remote';

    protected $table = 'users';

    protected $keyType = 'string';

    protected $fillable = ['name'];
}
