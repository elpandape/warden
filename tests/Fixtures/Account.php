<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;
use ElPandaPe\Bouncer\Concerns\QueriesByPermission;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasRolesAndPermissions;
    use QueriesByPermission;

    protected $fillable = ['name', 'user_id', 'owner_id', 'account_id'];
}
