<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * The least a consumer can legally swap in for the assignment pivot: without
 * warden's datetime cast, its end date reads back as the stored text.
 */
final class BareAssignedRole extends MorphPivot
{
    public $incrementing = true;

    protected $table = 'assigned_roles';

    public function usesTimestamps(): bool
    {
        return false;
    }
}
