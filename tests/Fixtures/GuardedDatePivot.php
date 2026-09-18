<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * A grant pivot that mass assigns only the columns naming a grant, which
 * warden's override contract allows: warden writes its end date unguarded.
 */
final class GuardedDatePivot extends MorphPivot
{
    public $incrementing = true;

    protected $table = 'grants';

    protected $fillable = ['permission_id', 'entity_type', 'entity_id', 'forbidden', 'scope'];

    public function usesTimestamps(): bool
    {
        return false;
    }
}
