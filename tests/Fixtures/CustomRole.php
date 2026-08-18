<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Concerns\IsRole;
use Illuminate\Database\Eloquent\Model;

final class CustomRole extends Model
{
    use IsRole;

    protected $fillable = ['name', 'title', 'scope'];
}
