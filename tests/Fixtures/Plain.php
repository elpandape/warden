<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Plain extends Model
{
    protected $table = 'users';

    protected $fillable = ['name'];
}
