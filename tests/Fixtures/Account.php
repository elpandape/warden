<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasRolesAndPermissions;

    protected $fillable = ['name', 'user_id', 'owner_id', 'account_id'];
}
