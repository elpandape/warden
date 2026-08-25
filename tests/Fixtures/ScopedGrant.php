<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Models\Grant;
use Illuminate\Database\Eloquent\Builder;

/**
 * A swapped grant model carrying a global scope of its own, which is the
 * customisation the override contract invites and the reason both engines must
 * read grants the same way.
 */
final class ScopedGrant extends Grant
{
    protected static function booted(): void
    {
        self::addGlobalScope('archived', function (Builder $query): void {
            $query->whereRaw('0 = 1');
        });
    }
}
