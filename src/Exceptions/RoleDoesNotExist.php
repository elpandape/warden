<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Exceptions;

use ElPandaPe\Bouncer\Context;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @extends ModelNotFoundException<\Illuminate\Database\Eloquent\Model>
 */
final class RoleDoesNotExist extends ModelNotFoundException
{
    public static function named(string $name): self
    {
        $exception = new self;
        $exception->setModel(Context::resolve()->roleClass());
        $exception->message = "Role [{$name}] does not exist.";

        return $exception;
    }
}
