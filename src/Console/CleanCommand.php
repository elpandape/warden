<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Warden;
use Illuminate\Console\Command;

final class CleanCommand extends Command
{
    protected $signature = 'warden:clean
        {--dry-run : Report what would be deleted without deleting}
        {--stranded : Also delete grants whose authority row is gone}';

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
            /** @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $permissions */
            function (\Illuminate\Support\Collection $permissions) use (&$deleted): void {
                foreach ($permissions as $permission) {
                    $permission->delete();
                    $deleted++;
                }
            },
        );

        $stranded = (bool) $this->option('stranded') ? $this->sweepStranded($context) : 0;

        $warden->refresh();

        $this->components->info("Deleted {$deleted} unused permission(s).");

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

            if (! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
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
}
