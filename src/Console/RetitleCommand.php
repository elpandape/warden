<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use ElPandaPe\Warden\Support\Titles\RoleTitle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class RetitleCommand extends Command
{
    protected $signature = 'warden:retitle
        {--dry-run : Report what would be rewritten without writing}';

    protected $description = 'Rewrite titles an older Warden generated, leaving titles a person wrote alone';

    public function handle(): int
    {
        $context = Context::resolve();
        $dry = (bool) $this->option('dry-run');

        $permissions = $this->converge(
            $context->permissionClass(),
            function (Model $permission): array {
                $name = $permission->getAttribute('name');
                $type = $permission->getAttribute('entity_type');
                $entityId = $permission->getAttribute('entity_id');

                return PermissionTitle::generations(
                    name: is_string($name) ? $name : '',
                    entityType: is_string($type) ? $type : null,
                    entityId: is_int($entityId) || is_string($entityId) ? $entityId : null,
                    onlyOwned: (bool) $permission->getAttribute('only_owned'),
                );
            },
            $dry,
        );

        $roles = $this->converge(
            $context->roleClass(),
            function (Model $role): array {
                $name = $role->getAttribute('name');

                return RoleTitle::generations(is_string($name) ? $name : '');
            },
            $dry,
        );

        $verb = $dry ? 'Would rewrite' : 'Rewrote';

        $this->components->info("{$verb} {$permissions} permission title(s) and {$roles} role title(s).");

        return self::SUCCESS;
    }

    /**
     * A stored title is warden's to rewrite only when it is one warden itself
     * could have written. Anything else was typed by a person, and a null was
     * chosen deliberately: both stay.
     *
     * @param  class-string<Model>  $class
     * @param  callable(Model): list<string>  $generations
     */
    private function converge(string $class, callable $generations, bool $dry): int
    {
        $rewritten = 0;

        // Titles live outside identity and outside the cache payload, so the
        // write needs no lifecycle hook and invalidates nothing.
        $class::query()->withoutGlobalScopes()->chunkById(
            100,
            /** @param Collection<int, Model> $rows */
            function (Collection $rows) use ($class, $generations, $dry, &$rewritten): void {
                foreach ($rows as $row) {
                    $title = $row->getAttribute('title');

                    if (! is_string($title)) {
                        continue;
                    }

                    $known = $generations($row);

                    if ($title === $known[0] || ! in_array($title, $known, true)) {
                        continue;
                    }

                    if (! $dry) {
                        $class::query()->withoutGlobalScopes()->getQuery()
                            ->where($row->getKeyName(), $row->getKey())
                            ->update(['title' => $known[0]]);
                    }

                    $rewritten++;
                }
            },
        );

        return $rewritten;
    }
}
