<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An assignment row a write created, or whose end date it moved.
 */
final readonly class AssignmentChange
{
    public function __construct(
        public Model $role,
        public bool $created,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $previousExpiresAt,
    ) {}
}
