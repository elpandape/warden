<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The end date on a pivot row that a write just found or created.
 *
 * Kept apart from the two actions because both need the same answer to the
 * same question: moving a date is a write. If it did not report that, cutting
 * an access short would leave the cache answering the old value and no event
 * behind for an audit trail to read.
 */
final class Expiry
{
    /**
     * Rows that still count: no end date, or one strictly ahead of now. The
     * boundary is exclusive, so a row expires at the instant it names rather
     * than a tick later.
     */
    public static function live(Builder $query, string $table = ''): void
    {
        $column = $table === '' ? 'expires_at' : "{$table}.expires_at";

        $query->where(function (Builder $live) use ($column): void {
            $live->whereNull($column)->orWhere($column, '>', Carbon::now());
        });
    }

    public static function apply(Model $row, ?DateTimeInterface $expiresAt): bool
    {
        $current = $row->getAttribute('expires_at');
        $current = $current instanceof DateTimeInterface ? $current->getTimestamp() : null;

        if ($current === $expiresAt?->getTimestamp()) {
            return false;
        }

        $row->setAttribute('expires_at', $expiresAt);
        $row->save();

        return true;
    }
}
