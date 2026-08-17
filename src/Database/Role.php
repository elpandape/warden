<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database;

use ElPandaPe\Bouncer\Database\Concerns\IsRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** @use HasFactory<\ElPandaPe\Bouncer\Database\Factories\RoleFactory> */
    use HasFactory;

    use IsRole;

    protected $fillable = [
        'name',
        'title',
        'scope',
    ];

    protected static function newFactory(): Factories\RoleFactory
    {
        return Factories\RoleFactory::new();
    }
}
