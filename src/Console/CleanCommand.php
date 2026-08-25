<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Warden;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CleanCommand extends Command
{
    protected $signature = 'warden:clean
        {--dry-run : Report what would be deleted without deleting}
        {--stranded : Also delete grants whose authority row is gone}
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

        $collapsed = (bool) $this->option('duplicates') ? $this->collapseDuplicates($context) : 0;
        $stranded = (bool) $this->option('stranded') ? $this->sweepStranded($context) : 0;

        $warden->refresh();

        $this->components->info("Deleted {$deleted} unused permission(s).");

        if ((bool) $this->option('duplicates')) {
            $this->components->info("Collapsed {$collapsed} duplicate catalog row(s).");
        }

        if ((bool) $this->option('stranded')) {
            $this->components->info("Deleted {$stranded} stranded grant(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Grants whose authority no longer exists: a role or user deleted outside
     * warden leaves them behind, and no foreign key reaches a morph pair.
     *
     * An alias that maps to no class is reported, never deleted — the class may
     * simply not be loaded in this process.
     */
    private function sweepStranded(Context $context): int
    {
        $grantModel = new ($context->grantClass());
        $deleted = 0;

        $types = $context->grantClass()::query()->withoutGlobalScopes()->getQuery()
            ->whereNotNull('entity_type')->distinct()->pluck('entity_type');

        foreach ($types as $type) {
            if (! is_string($type)) {
                continue; // @codeCoverageIgnore
            }

            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                $this->components->warn("Skipping [{$type}]: no class maps to it here.");

                continue;
            }

            $authority = new $class;

            $deleted += (int) $context->grantClass()::query()->withoutGlobalScopes()->getQuery()
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
