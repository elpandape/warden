<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

enum PermissionName: string
{
    case EditSite = 'edit-site';
    case Publish = 'publish';
}
