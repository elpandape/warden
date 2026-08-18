<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;
use ElPandaPe\Bouncer\Concerns\QueriesByPermission;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
    use QueriesByPermission;

    protected $fillable = ['name'];
}
