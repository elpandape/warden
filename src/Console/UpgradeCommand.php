<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Console;

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Context;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-place upgrade from a silber/bouncer schema. The dangerous crossing is
 * the name swap: the original's `permissions` table is the PIVOT (our
 * `grants`), while our `permissions` is its `abilities` — so the pivot must
 * move first, in this exact order:
 *
 *   permissions -> grants  (+ ability_id -> permission_id)
 *   abilities   -> permissions
 *
 * Role grants keep working through a morph rewrite, and legacy constraint
 * blobs are cleared: the original never evaluated them, so NULL preserves
 * the behavior users actually had.
 */
final class UpgradeCommand extends Command
{
    private const array LEGACY_ROLE_MORPHS = ['Silber\Bouncer\Database\Role', 'roles'];

    protected $signature = 'bouncer:upgrade
        {--dry-run : Report what would happen without touching anything}
        {--allow-open-scopes : Proceed even though null_behavior=all widens legacy tenant isolation}
        {--role-morph=* : Extra legacy role morph values to rewrite (e.g. App\\Models\\Role)}';

    protected $description = 'Upgrade a silber/bouncer database schema in place';

    public function handle(Bouncer $bouncer): int
    {
        if (! Schema::hasTable('abilities') || ! Schema::hasColumn('permissions', 'ability_id')) {
            $this->components->error(
                'No silber/bouncer schema detected: expected an [abilities] table and a [permissions] pivot with [ability_id].',
            );

            return self::FAILURE;
        }

        if (Schema::hasTable('grants')) {
            $this->components->error('A [grants] table already exists: this looks like a fresh install, not an upgrade.');

            return self::FAILURE;
        }

        $abilities = DB::table('abilities')->count();
        $pivots = DB::table('permissions')->count();
        $constrained = DB::table('abilities')->whereNotNull('options')->count();
        $roleGrants = DB::table('permissions')->whereIn('entity_type', $this->legacyRoleMorphs())->count();
        $roleTargets = DB::table('abilities')->whereIn('entity_type', $this->legacyRoleMorphs())->count();
        $scoped = DB::table('permissions')->whereNotNull('scope')->count()
            + DB::table('abilities')->whereNotNull('scope')->count()
            + DB::table('assigned_roles')->whereNotNull('scope')->count();

        $this->components->info('silber/bouncer schema detected.');
        $this->components->twoColumnDetail('Abilities to become permissions', (string) $abilities);
        $this->components->twoColumnDetail('Pivot rows to become grants', (string) $pivots);
        $this->components->twoColumnDetail('Role grants to re-morph', (string) $roleGrants);
        $this->components->twoColumnDetail('Role-targeted abilities to re-morph', (string) $roleTargets);
        $this->components->twoColumnDetail('Legacy constraint blobs to clear', (string) $constrained);
        $this->components->twoColumnDetail('Tenant-scoped rows', (string) $scoped);

        if ((bool) $this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        // Legacy checks hid every tenant-scoped row when no scope was active;
        // the shipped default 'all' would surface them globally. Fail closed.
        $widens = $scoped > 0
            && \ElPandaPe\Bouncer\Support\Config::scopeNullBehavior() === 'all'
            && ! (bool) $this->option('allow-open-scopes');

        if ($widens) {
            $this->components->error(
                "Found {$scoped} tenant-scoped row(s), and bouncer.scope.null_behavior is 'all': "
                ."scope-less checks would see every tenant's rows, unlike your legacy install. "
                ."Set 'null_behavior' => 'strict' to preserve legacy semantics, "
                .'or re-run with --allow-open-scopes if widening is intentional.',
            );

            return self::FAILURE;
        }

        $steps = function (): void {
            Schema::rename('permissions', 'grants');
            Schema::table('grants', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->renameColumn('ability_id', 'permission_id');
            });
            Schema::rename('abilities', 'permissions');

            // The original stamped its own Role morph on role-held rows; ours
            // must match the configured alias or those grants go silent.
            $roleMorph = (new (Context::resolve()->roleClass()))->getMorphClass();

            DB::table('grants')
                ->whereIn('entity_type', $this->legacyRoleMorphs())
                ->update(['entity_type' => $roleMorph]);

            // Abilities can TARGET the role model too (manage roles, etc.):
            // the catalog's entity_type needs the same rewrite.
            DB::table('permissions')
                ->whereIn('entity_type', $this->legacyRoleMorphs())
                ->update(['entity_type' => $roleMorph]);

            // The original serialized constraints it never evaluated; clearing
            // them preserves the behavior users actually had (fail-closed
            // deserialization would silence those grants instead).
            DB::table('permissions')->whereNotNull('options')->update(['options' => null]);
        };

        // Postgres and SQLite give us atomic DDL; MySQL auto-commits each
        // statement, so wrapping there would only fake safety and then break.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::transaction($steps);
        } else {
            // Exercised by the real-database suite, not the SQLite pass.
            $steps(); // @codeCoverageIgnore
        }

        $bouncer->refresh();

        $this->components->info('Upgrade complete. Review indexes if you tuned them, then run your test suite.');
        $this->components->info('Update code imports with the Rector set: see UPGRADE.md.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function legacyRoleMorphs(): array
    {
        $extra = $this->option('role-morph');

        return array_values(array_unique([
            ...self::LEGACY_ROLE_MORPHS,
            ...array_filter(is_array($extra) ? $extra : [], is_string(...)),
        ]));
    }
}
