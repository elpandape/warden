<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Plain extends Model
{
    protected $table = 'users';

    protected $fillable = ['name'];
}
