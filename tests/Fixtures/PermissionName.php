<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Tests\Fixtures;

enum PermissionName: string
{
    case EditSite = 'edit-site';
    case Publish = 'publish';
}
