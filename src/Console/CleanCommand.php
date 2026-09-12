<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Warden;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CleanCommand extends Command
{
    private const int AUTHORITY_KEY_BATCH = 500;

    protected $signature = 'warden:clean
        {--dry-run : Report what would be deleted without deleting}
        {--stranded : Also delete grants and assignments whose authority row is gone}
        {--expired : Also delete grants and role assignments whose end date has passed}
        {--duplicates : Also collapse catalog rows that identify the same permission}';

    protected $description = 'Delete unused permissions: catalog rows no grant points at';

    public function handle(Warden $warden): int
    {
        $context = Context::resolve();
        $grantModel = new ($context->grantClass());

        // Unused rows have no tenant: scan the whole catalog explicitly.
        $unused = $context->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $query) use ($context, $grantModel): void {
                $query->from($grantModel->getTable())
                    ->whereColumn(
                        $grantModel->qualifyColumn('permission_id'),
                        (new ($context->permissionClass()))->getQualifiedKeyName(),
                    );
            });

        if ((bool) $this->option('dry-run')) {
            $this->components->info("Would delete {$unused->count()} unused permission(s).");

            if ((bool) $this->option('expired')) {
                $this->components->info("Would delete {$this->expiredCount($context)} expired row(s).");
            }

            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunked: model deletes keep firing lifecycle events per row.
        $unused->chunkById(
            100,
            /** @param Collection<int, Model> $permissions */
            function (Collection $permissions) use (&$deleted): void {
                foreach ($permissions as $permission) {
                    $permission->delete();
                    $deleted++;
                }
            },
        );

        $expired = (bool) $this->option('expired') ? $this->sweepExpired($context) : 0;
        $collapsed = (bool) $this->option('duplicates') ? $this->collapseDuplicates($context) : 0;
        $stranded = (bool) $this->option('stranded') ? $this->sweepStranded($context) : 0;

        $warden->refresh();

        $this->components->info("Deleted {$deleted} unused permission(s).");

        if ((bool) $this->option('duplicates')) {
            $this->components->info("Collapsed {$collapsed} duplicate catalog row(s).");
        }

        if ((bool) $this->option('stranded')) {
            $this->components->info("Deleted {$stranded} stranded row(s).");
        }

        if ((bool) $this->option('expired')) {
            $this->components->info("Deleted {$expired} expired row(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Rows past their end date, on both pivots.
     *
     * Hygiene, never load-bearing: an expired grant stops authorizing whether
     * or not anyone runs this. The moment something needs the command to have
     * run in order to deny, expiry has the shape of the competitor's.
     */
    private function expiredCount(Context $context): int
    {
        $total = 0;

        foreach ([$context->grantClass(), $context->assignedRoleClass()] as $class) {
            $total += $class::query()->withoutGlobalScopes()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', Carbon::now())
                ->count();
        }

        return $total;
    }

    private function sweepExpired(Context $context): int
    {
        $deleted = 0;

        foreach ([$context->grantClass(), $context->assignedRoleClass()] as $class) {
            $rows = $class::query()->withoutGlobalScopes()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', Carbon::now())
                ->toBase()
                ->delete();

            $deleted += $rows;
        }

        return $deleted;
    }

    /**
     * Rows whose authority no longer exists: a role or user deleted outside
     * warden leaves them behind, and no foreign key reaches a morph pair.
     *
     * Both pivots, because nesting made assigned_roles able to hold an edge
     * whose authority is a role: role_id cascades, but the parent side lives in
     * entity_type/entity_id, which no foreign key reaches.
     *
     * An alias that maps to no class is reported, never deleted — the class may
     * simply not be loaded in this process.
     */
    private function sweepStranded(Context $context): int
    {
        return $this->sweepStrandedIn($context->grantClass())
            + $this->sweepStrandedIn($context->assignedRoleClass());
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function sweepStrandedIn(string $class): int
    {
        $grantModel = new $class;
        $deleted = 0;

        $types = $class::query()->withoutGlobalScopes()->getQuery()
            ->whereNotNull('entity_type')->distinct()->pluck('entity_type');

        foreach ($types as $type) {
            if (! is_string($type)) {
                continue; // @codeCoverageIgnore
            }

            $authorityClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($authorityClass, Model::class)) {
                $this->components->warn("Skipping [{$type}]: no class maps to it here.");

                continue;
            }

            $authority = new $authorityClass;

            if ($authority->getConnection()->getName() !== $grantModel->getConnection()->getName()) {
                $deleted += $this->sweepStrandedAcrossConnections($class, $type, $authority);

                continue;
            }

            $deleted += (int) $class::query()->withoutGlobalScopes()->getQuery()
                ->where('entity_type', $type)
                ->whereNotExists(function (\Illuminate\Database\Query\Builder $query) use ($authority, $grantModel): void {
                    $query->from($authority->getTable())
                        ->whereColumn($authority->getQualifiedKeyName(), $grantModel->qualifyColumn('entity_id'));
                })
                ->delete();
        }

        return $deleted;
    }

    /**
     * The authority table lives on another connection, out of reach of any
     * subquery on the pivot's: ask the authority model itself, one batch of
     * keys at a time. Without its global scopes, since a row one hides still
     * exists. A row with no key names nobody, as the subquery reads it too.
     * A key the batch hands back in another form, as a collation or padding
     * allows, is asked for again on its own, so the authority's database, not
     * PHP, decides who is gone.
     *
     * @param  class-string<Model>  $class
     */
    private function sweepStrandedAcrossConnections(string $class, string $type, Model $authority): int
    {
        $rows = fn (): \Illuminate\Database\Query\Builder => $class::query()->withoutGlobalScopes()->getQuery()
            ->where('entity_type', $type);

        $deleted = $rows()->whereNull('entity_id')->delete();

        $keys = $rows()->whereNotNull('entity_id')->distinct()->pluck('entity_id')->all();

        foreach (array_chunk($keys, self::AUTHORITY_KEY_BATCH) as $batch) {
            $present = array_map(
                $this->comparableKey(...),
                $authority->newQueryWithoutScopes()->whereIn($authority->getQualifiedKeyName(), $batch)->pluck($authority->getKeyName())->all(),
            );

            $gone = array_values(array_filter(
                $batch,
                fn (mixed $key): bool => ! in_array($this->comparableKey($key), $present, true)
                    && ! $authority->newQueryWithoutScopes()->where($authority->getQualifiedKeyName(), $key)->exists(),
            ));

            if ($gone === []) {
                continue;
            }

            $deleted += $rows()->whereIn('entity_id', $gone)->delete();
        }

        return $deleted;
    }

    /**
     * Two connections can hand the same key back as an int and as a string.
     */
    private function comparableKey(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '';
    }

    /**
     * Catalog rows that identify the same permission.
     *
     * SQL narrows to candidates on the columns that group cleanly; PHP splits
     * each candidate set, because the options blob's identity is defined by
     * ConstraintSerializer and no database can reproduce it. The lowest id wins;
     * the losers' grants are re-pointed at it, dropping any that would collide
     * with a grant the keeper already has.
     */
    private function collapseDuplicates(Context $context): int
    {
        $permissionClass = $context->permissionClass();
        $grantClass = $context->grantClass();
        $collapsed = 0;

        $groups = $permissionClass::query()->withoutGlobalScopes()->get()
            ->groupBy(fn (Model $row): string => implode("\x1f", array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : '~',
                [
                    $row->getAttribute('name'),
                    $row->getAttribute('entity_type'),
                    $row->getAttribute('entity_id'),
                    $row->getAttribute('only_owned') ? '1' : '0',
                    $row->getAttribute('scope'),
                ],
            )));

        foreach ($groups as $group) {
            foreach ($this->sameRuleClusters($group->all()) as $cluster) {
                if (count($cluster) < 2) {
                    continue;
                }

                usort($cluster, fn (Model $a, Model $b): int => $a->getKey() <=> $b->getKey());
                $keeper = $cluster[0];

                foreach ($cluster as $loser) {
                    if ($loser->is($keeper)) {
                        continue;
                    }

                    $this->rePoint($grantClass, $loser, $keeper);
                    $loser->delete();
                    $collapsed++;
                }
            }
        }

        return $collapsed;
    }

    /**
     * @param  array<array-key, Model>  $group
     * @return list<list<Model>>
     */
    private function sameRuleClusters(array $group): array
    {
        /** @var list<list<Model>> $clusters */
        $clusters = [];

        foreach ($group as $row) {
            // Ask the column, not the cast: an undecodable blob casts to null,
            // which reads as "no conditions" and would collapse a narrowed rule
            // into its unconstrained sister.
            $stored = $row->getAttributes()['options'] ?? null;

            if ($stored !== null && ! ConstraintSerializer::deserialize($stored) instanceof Group) {
                $key = $row->getKey();

                if (! is_int($key) && ! is_string($key)) {
                    continue; // @codeCoverageIgnore
                }

                $this->components->warn("Skipping permission [{$key}]: its options do not decode.");

                continue;
            }

            foreach ($clusters as $index => $cluster) {
                if (ConstraintSerializer::sameRule($cluster[0]->getAttribute('options'), $row->getAttribute('options'))) {
                    $clusters[$index][] = $row;

                    continue 2;
                }
            }

            $clusters[] = [$row];
        }

        return $clusters;
    }

    /**
     * @param  class-string<Model>  $grantClass
     */
    private function rePoint(string $grantClass, Model $loser, Model $keeper): void
    {
        $grants = $grantClass::query()->withoutGlobalScopes()
            ->getQuery()->where('permission_id', $loser->getKey())->get();

        foreach ($grants as $grant) {
            $taken = $grantClass::query()->withoutGlobalScopes()->getQuery()
                ->where('permission_id', $keeper->getKey())
                ->where('entity_type', $grant->entity_type)
                ->where('entity_id', $grant->entity_id)
                ->where('forbidden', $grant->forbidden)
                ->where('scope', $grant->scope)
                ->exists();

            $query = $grantClass::query()->withoutGlobalScopes()->getQuery()->where('id', $grant->id);

            // Re-pointing can collide with a grant the keeper already holds.
            $taken ? $query->delete() : $query->update(['permission_id' => $keeper->getKey()]);
        }
    }
}
