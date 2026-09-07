<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Models\Concerns\ResolvesContext;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|string $permission_id
 * @property string|null $entity_type
 * @property int|string|null $entity_id
 * @property bool $forbidden
 * @property int|null $scope
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class Grant extends MorphPivot
{
    use BelongsToTenant;
    use ResolvesContext;

    public $incrementing = true;

    public function usesTimestamps(): bool
    {
        return Config::pivotTimestamps();
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Context::resolve()->permissionClass(), 'permission_id');
    }

    /**
     * The holder. Null by design: a grant with no entity applies to everyone.
     *
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forbidden' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected function contextTableKey(): string
    {
        return 'grants';
    }
}
