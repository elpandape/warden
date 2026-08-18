<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Exceptions;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @extends ModelNotFoundException<\Illuminate\Database\Eloquent\Model>
 */
final class PermissionDoesNotExist extends ModelNotFoundException
{
    public static function named(string $name): self
    {
        $exception = new self;
        $exception->setModel(Context::resolve()->permissionClass());
        $exception->message = "Permission [{$name}] does not exist.";

        return $exception;
    }
}
