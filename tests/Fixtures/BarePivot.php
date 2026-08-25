<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * The least a consumer can legally swap in for the grant pivot: warden's
 * override contract asks only for a MorphPivot, so this carries none of
 * warden's own traits.
 */
final class BarePivot extends MorphPivot
{
    public $incrementing = true;

    protected $table = 'grants';

    public function usesTimestamps(): bool
    {
        return false;
    }
}
