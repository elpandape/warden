<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Database\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Model;

final class Account extends Model
{
    use HasRolesAndPermissions;

    protected $fillable = ['name'];
}
