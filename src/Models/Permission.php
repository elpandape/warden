<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Models;

use ElPandaPe\Bouncer\Database\Factories\PermissionFactory;
use ElPandaPe\Bouncer\Models\Concerns\IsPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $title
 * @property string|null $entity_type
 * @property int|string|null $entity_id
 * @property bool $only_owned
 * @property array<array-key, mixed>|null $options
 * @property int|null $scope
 */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    use IsPermission;

    protected $fillable = [
        'name',
        'title',
        'entity_type',
        'entity_id',
        'only_owned',
        'options',
        'scope',
    ];

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }
}
