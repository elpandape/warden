<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models;

use ElPandaPe\Bouncer\Database\Factories\RoleFactory;
use ElPandaPe\Bouncer\Models\Concerns\IsRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $title
 * @property int|null $scope
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use IsRole;

    protected $fillable = [
        'name',
        'title',
        'scope',
    ];

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
