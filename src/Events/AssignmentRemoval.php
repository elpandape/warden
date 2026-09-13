<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An assignment row a removal deleted: the context it was restricted to, and
 * the end date it still carried.
 */
final readonly class AssignmentRemoval
{
    public function __construct(
        public Model $role,
        public ?Model $restrictedTo,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
