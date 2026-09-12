<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use DateTimeInterface;
use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every role an authority reaches, with the restriction of the assignment it
 * was reached through.
 *
 * Not a flat list of keys: that shape serves one of the six readers and
 * strands the rest. CachedResolver::build() needs the restriction pair per
 * role without an entity, WhereCan::activeKeys() filters by polarity,
 * anyGrantHeld() wants the unfiltered union precisely because narrowing there
 * fails open, and Explainer needs identity. Each reader applies its own filter
 * over this, unchanged.
 *
 * The nesting edge lives in assigned_roles like any other assignment — a role
 * as the authority — so the schema is untouched. What is dedicated is the way
 * it is read, not where it is stored: giving Role the authority concern would
 * drag roles(), isA() and the tenancy scopes onto the role model and make
 * every role an authority for the whole engine.
 */
final class RoleClosure
{
    /**
     * Each entry carries the restriction pair AND the end date of the weakest
     * link that led there, because the cached engine compares that date in
     * memory rather than filtering it in SQL.
     *
     * @param  iterable<Model>|null  $assignments
     * @return array<int|string, list<array{string|null, int|string|null, int|null}>>
     */
    public static function for(Model $authority, ?iterable $assignments = null): array
    {
        $context = Context::resolve();

        // Reuse the rows a caller already read rather than reading them again:
        // explain() and the resolver share one query, and nesting off needs no
        // second level at all.
        $direct = $assignments === null
            ? self::edgesFrom($context, $authority->getMorphClass(), $authority->getKey())
            : self::edgesIn($assignments);

        if (! Config::nestedRoles()) {
            return $direct;
        }

        $roleMorph = (new ($context->roleClass()))->getMorphClass();
        $reached = $direct;
        $frontier = array_keys($direct);
        $depth = Config::roleMaxDepth();

        while ($frontier !== [] && $depth-- > 0) {
            $next = [];

            foreach ($frontier as $roleKey) {
                foreach (self::edgesFrom($context, $roleMorph, $roleKey) as $inner => $restrictions) {
                    // The outer assignment's restriction wins: reaching a role
                    // through a restricted one cannot be broader than the step
                    // that got there.
                    $carried = $reached[$roleKey] ?? [[null, null, null]];

                    if (! array_key_exists($inner, $reached)) {
                        $reached[$inner] = [];
                        $next[] = $inner;
                    }

                    foreach ($carried as [$type, $id, $carriedEnds]) {
                        foreach ($restrictions as [, , $innerEnds]) {
                            // A role reached through another outlives neither
                            // step: the earliest end date on the path wins.
                            $ends = self::earliest($carriedEnds, $innerEnds);
                            $entry = [$type, $id, $ends];

                            if (! in_array($entry, $reached[$inner], true)) {
                                $reached[$inner][] = $entry;
                            }
                        }
                    }
                }
            }

            $frontier = $next;
        }

        return $reached;
    }

    /**
     * The roles that reach these, themselves included — the closure walked
     * upwards. is() answers from the authority's side; whereIs() has to answer
     * from the role's, because it filters authorities and cannot expand a
     * closure per row.
     *
     * @param  list<int|string>  $targets
     * @return list<int|string>
     */
    public static function reaching(array $targets): array
    {
        if ($targets === []) {
            return $targets;
        }

        $context = Context::resolve();
        $roleMorph = (new ($context->roleClass()))->getMorphClass();
        $reached = $targets;
        $frontier = $targets;
        $depth = Config::roleMaxDepth();

        while ($frontier !== [] && $depth-- > 0) {
            $outer = $context->assignedRoleClass()::query()
                ->where('entity_type', $roleMorph)
                ->whereIn('role_id', $frontier)
                ->tap(Expiry::live(...))
                ->toBase()
                ->pluck('entity_id')
                ->all();

            $frontier = [];

            foreach ($outer as $key) {
                if ((is_int($key) || is_string($key)) && ! in_array($key, $reached, true)) {
                    $reached[] = $key;
                    $frontier[] = $key;
                }
            }
        }

        return $reached;
    }

    private static function earliest(?int $first, ?int $second): ?int
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return min($first, $second);
    }

    /**
     * @return array<int|string, list<array{string|null, int|string|null, int|null}>>
     */
    private static function edgesFrom(Context $context, string $morph, mixed $key): array
    {
        return self::edgesIn($context->assignedRoleClass()::query()
            ->where('entity_type', $morph)
            ->where('entity_id', $key)
            ->tap(Expiry::live(...))
            ->get());
    }

    /**
     * @param  iterable<Model>  $assignments
     * @return array<int|string, list<array{string|null, int|string|null, int|null}>>
     */
    private static function edgesIn(iterable $assignments): array
    {
        $edges = [];

        foreach ($assignments as $assignment) {
            $roleKey = $assignment->getAttribute('role_id');

            if (! is_int($roleKey) && ! is_string($roleKey)) {
                continue; // @codeCoverageIgnore
            }

            $type = $assignment->getAttribute('restricted_to_type');
            $id = $assignment->getAttribute('restricted_to_id');

            $type = is_string($type) ? $type : null;
            $id = is_int($id) || is_string($id) ? $id : null;

            // A half-written restriction is not "unrestricted": fail closed.
            if (($type === null) !== ($id === null)) {
                continue;
            }

            /** @var DateTimeInterface|string|null $ends */
            $ends = $assignment->getAttribute('expires_at');

            if ($ends !== null) {
                // An assignment model swapped in without warden's datetime cast reads back the stored text.
                $ends = ($ends instanceof DateTimeInterface ? $ends : Carbon::parse($ends))->getTimestamp();
            }

            $edges[$roleKey][] = [$type, $id, $ends];
        }

        return $edges;
    }
}
