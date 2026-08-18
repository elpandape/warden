<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

enum RoleName: string
{
    case Admin = 'admin';
    case Editor = 'editor';
}
