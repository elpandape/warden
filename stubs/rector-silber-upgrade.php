<?php

declare(strict_types=1);

/*
 * One-shot Rector set to migrate an app from silber/bouncer to
 * elpandape/warden. Include it from your rector.php (or run it alone:
 * vendor/bin/rector process app --config vendor/elpandape/warden/stubs/rector-silber-upgrade.php),
 * then remove it — it is an upgrade tool, not a permanent rule set.
 */

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\ValueObject\MethodCallRename;

return RectorConfig::configure()
    ->withPaths([getcwd().'/app', getcwd().'/tests', getcwd().'/database', getcwd().'/routes'])
    ->withConfiguredRule(RenameClassRector::class, [
        // The root facade alias. Both packages used to register `Bouncer`, so
        // call sites that never imported it kept working across the swap; this
        // package registers `Warden`, so they no longer do. Covers `use Bouncer;`,
        // `\Bouncer::` and un-namespaced files (routes/*). The one thing it would
        // also catch is an app class literally named `Bouncer` in the global
        // namespace — drop this line if you have one.
        'Bouncer' => 'ElPandaPe\Warden\Facades\Warden',
        'Silber\Bouncer\BouncerFacade' => 'ElPandaPe\Warden\Facades\Warden',
        'Silber\Bouncer\Bouncer' => 'ElPandaPe\Warden\Warden',
        'Silber\Bouncer\Database\Ability' => 'ElPandaPe\Warden\Models\Permission',
        'Silber\Bouncer\Database\Role' => 'ElPandaPe\Warden\Models\Role',
        'Silber\Bouncer\Database\HasRolesAndAbilities' => 'ElPandaPe\Warden\Concerns\HasRolesAndPermissions',
        'Silber\Bouncer\Database\Concerns\IsAbility' => 'ElPandaPe\Warden\Models\Concerns\IsPermission',
        'Silber\Bouncer\Database\Concerns\IsRole' => 'ElPandaPe\Warden\Models\Concerns\IsRole',
        'Silber\Bouncer\Contracts\Scope' => 'ElPandaPe\Warden\Contracts\TenantResolver',
    ])
    ->withConfiguredRule(RenameMethodRector::class, [
        new MethodCallRename('ElPandaPe\Warden\Warden', 'ability', 'permission'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'getAbilities', 'getPermissions'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'getForbiddenAbilities', 'getForbiddenPermissions'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'abilities', 'permissions'),
    ]);
