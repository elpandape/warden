<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Role;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SoftDeletingRole extends Role
{
    use SoftDeletes;
}
