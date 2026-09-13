<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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
 * behind for an audit trail to read. It is also how every event reads that
 * date back: of() gives wall time in the application's zone, cast or not.
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
        // Compared as the column stores it, wall time in the connection's
        // format: a moment in another zone that stores the same value writes
        // nothing, and a pivot that casts no date still reads what it holds.
        if (self::stored($row) === $row->fromDateTime($expiresAt)) {
            return false;
        }

        $row->setAttribute('expires_at', $expiresAt);
        $row->save();

        return true;
    }

    /**
     * The end date a row holds, read back the way the column keeps it: wall
     * time in the application's zone, whether or not the row casts it.
     */
    public static function of(Model $row): ?CarbonImmutable
    {
        $stored = self::stored($row);

        if ($stored === null) {
            return null;
        }

        // MySQL's zero date comes back as year -1, which the format cannot
        // read again: parse it instead, as Eloquent's asDateTime() does. The
        // format throws on it in Carbon's strict mode, and returns null without.
        try {
            return CarbonImmutable::createFromFormat($row->getDateFormat(), $stored) ?? CarbonImmutable::parse($stored);
        } catch (InvalidFormatException) {
            return CarbonImmutable::parse($stored);
        }
    }

    private static function stored(Model $row): ?string
    {
        $stored = $row->fromDateTime($row->getAttributes()['expires_at'] ?? null);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }
}
