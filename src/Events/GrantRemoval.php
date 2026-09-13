<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A grant row a removal deleted, with the end date it still carried.
 */
final readonly class GrantRemoval
{
    public function __construct(
        public Model $permission,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
