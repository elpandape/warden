<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A grant row a write created, or whose end date it moved.
 */
final readonly class GrantChange
{
    public function __construct(
        public Model $permission,
        public bool $created,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $previousExpiresAt,
    ) {}
}
