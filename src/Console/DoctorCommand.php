<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Context;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Reads the catalogue back through the rule the write path now enforces, and
 * reports what it would refuse today. Rows written before that refusal are not
 * repaired here: adding the cast or rewriting the condition changes what the
 * rule means, and only the consumer knows which one they meant.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'warden:doctor';

    protected $description = 'Audit the permission catalog for rules that can never be true';

    public function handle(): int
    {
        $context = Context::resolve();
        $findings = 0;

        // Catalogue-wide by definition: a broken rule in another tenant is
        // still a broken rule, and this is the only place that says so.
        $permissions = $context->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereNotNull('options')
            ->get();

        foreach ($permissions as $permission) {
            $type = $permission->getAttribute('entity_type');

            if (! is_string($type)) {
                continue;
            }

            $columns = $this->unsatisfiableColumns($permission, $type);

            if ($columns === []) {
                continue;
            }

            $findings++;
            $this->report($permission, $type, $columns);
        }

        if ($findings === 0) {
            $this->components->info('No stored condition can be ruled out: every rule in the catalog is satisfiable.');

            return self::SUCCESS;
        }

        $this->components->error("{$findings} stored condition(s) can never be true.");
        $this->line('  Nothing was changed. Add the missing cast, or rewrite the condition.');

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function unsatisfiableColumns(Model $permission, string $type): array
    {
        if ($type === '*') {
            return [];
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class)) {
            return [];
        }

        $group = ConstraintSerializer::deserialize($permission->getAttribute('options'));

        return $group instanceof Group ? $group->unsatisfiableColumns(new $class) : [];
    }

    /**
     * @param  list<string>  $columns
     */
    private function report(Model $permission, string $type, array $columns): void
    {
        $grants = DB::table((new (Context::resolve()->grantClass()))->getTable())
            ->where('permission_id', $permission->getKey());

        $forbidden = (clone $grants)->where('forbidden', true)->count();
        $granted = (clone $grants)->where('forbidden', false)->count();
        $name = $permission->getAttribute('name');

        $this->components->twoColumnDetail(
            sprintf('<fg=red>%s</> on %s', is_string($name) ? $name : $type, $type),
            sprintf('forbid x%d, grant x%d', $forbidden, $granted),
        );

        foreach ($columns as $column) {
            $this->line("    [{$column}] is compared as a boolean against a column that is not cast to bool");
        }

        if ($forbidden > 0) {
            $this->line('    <fg=yellow>this forbid never fires: the grant beneath it stays live</>');
        }
    }
}
