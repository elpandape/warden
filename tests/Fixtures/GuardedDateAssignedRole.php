<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * An assignment pivot that mass assigns only the columns naming an assignment,
 * which warden's override contract allows: warden writes its end date unguarded.
 */
final class GuardedDateAssignedRole extends MorphPivot
{
    public $incrementing = true;

    protected $table = 'assigned_roles';

    protected $fillable = ['role_id', 'entity_type', 'entity_id', 'restricted_to_type', 'restricted_to_id', 'scope'];

    public function usesTimestamps(): bool
    {
        return false;
    }
}
