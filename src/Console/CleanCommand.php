<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Warden;
use Illuminate\Console\Command;

final class CleanCommand extends Command
{
    protected $signature = 'warden:clean {--dry-run : Report what would be deleted without deleting}';

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

        $warden->refresh();

        $this->components->info("Deleted {$deleted} unused permission(s).");

        return self::SUCCESS;
    }
}
