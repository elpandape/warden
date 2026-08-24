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
 * @property int|string $role_id
 * @property string $entity_type
 * @property int|string $entity_id
 * @property string|null $restricted_to_type
 * @property int|string|null $restricted_to_id
 * @property int|null $scope
 */
class AssignedRole extends MorphPivot
{
    use BelongsToTenant;
    use ResolvesContext;

    public $incrementing = true;

    public function usesTimestamps(): bool
    {
        return Config::pivotTimestamps();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Context::resolve()->roleClass(), 'role_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * The context a restricted assignment is pinned to, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function restrictedTo(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'restricted_to_type', 'restricted_to_id');
    }

    protected function contextTableKey(): string
    {
        return 'assigned_roles';
    }
}
