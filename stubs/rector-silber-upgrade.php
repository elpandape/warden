<?php

declare(strict_types=1);

/*
 * One-shot Rector set to migrate an app from silber/bouncer to
 * elpandape/bouncer. Include it from your rector.php (or run it alone:
 * vendor/bin/rector process app --config vendor/elpandape/bouncer/stubs/rector-silber-upgrade.php),
 * then remove it — it is an upgrade tool, not a permanent rule set.
 */

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\ValueObject\MethodCallRename;

return RectorConfig::configure()
    ->withPaths([getcwd().'/app', getcwd().'/tests', getcwd().'/database', getcwd().'/routes'])
    ->withConfiguredRule(RenameClassRector::class, [
        'Silber\Bouncer\BouncerFacade' => 'ElPandaPe\Bouncer\Facades\Bouncer',
        'Silber\Bouncer\Bouncer' => 'ElPandaPe\Bouncer\Bouncer',
        'Silber\Bouncer\Database\Ability' => 'ElPandaPe\Bouncer\Models\Permission',
        'Silber\Bouncer\Database\Role' => 'ElPandaPe\Bouncer\Models\Role',
        'Silber\Bouncer\Database\HasRolesAndAbilities' => 'ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions',
        'Silber\Bouncer\Database\Concerns\IsAbility' => 'ElPandaPe\Bouncer\Models\Concerns\IsPermission',
        'Silber\Bouncer\Database\Concerns\IsRole' => 'ElPandaPe\Bouncer\Models\Concerns\IsRole',
        'Silber\Bouncer\Contracts\Scope' => 'ElPandaPe\Bouncer\Contracts\TenantResolver',
    ])
    ->withConfiguredRule(RenameMethodRector::class, [
        new MethodCallRename('ElPandaPe\Bouncer\Bouncer', 'ability', 'permission'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'getAbilities', 'getPermissions'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'getForbiddenAbilities', 'getForbiddenPermissions'),
        new MethodCallRename('Illuminate\Database\Eloquent\Model', 'abilities', 'permissions'),
    ]);
