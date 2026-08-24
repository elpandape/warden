<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Tests\Fixtures;

use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Model;

final class NarrowSignatureAuthority extends Model
{
    use HasRolesAndPermissions;

    public function isA(string $primary = '', string ...$others): bool
    {
        return $primary === 'editor';
    }

    public function isAll(string $primary = '', string ...$others): bool
    {
        return $primary === 'editor';
    }
}
