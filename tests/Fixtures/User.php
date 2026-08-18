<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use ElPandaPe\Warden\Concerns\QueriesByPermission;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
    use QueriesByPermission;

    protected $fillable = ['name'];
}
