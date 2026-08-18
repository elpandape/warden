<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use ElPandaPe\Warden\Concerns\QueriesByPermission;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasRolesAndPermissions;
    use QueriesByPermission;

    protected $fillable = ['name', 'user_id', 'owner_id', 'account_id'];
}
