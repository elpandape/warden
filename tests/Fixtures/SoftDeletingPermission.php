<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Permission;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SoftDeletingPermission extends Permission
{
    use SoftDeletes;
}
