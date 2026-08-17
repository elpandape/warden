<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

use ElPandaPe\Bouncer\Database\Concerns\IsRole;
use Illuminate\Database\Eloquent\Model;

final class CustomRole extends Model
{
    use IsRole;

    protected $fillable = ['name', 'title', 'scope'];
}
