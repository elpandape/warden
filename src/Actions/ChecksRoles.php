<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions;

use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ChecksRoles
{
    public function __construct(private readonly Model $authority)
    {
        // A same-named method on an unrelated model must not answer authorization checks.
        if (! in_array(HasRolesAndPermissions::class, class_uses_recursive($authority), true)) {
            throw new InvalidArgumentException(
                'The authority must use the HasRolesAndPermissions concern to check roles.',
            );
        }
    }

    public function a(string ...$roles): bool
    {
        return $this->check('isA', array_values($roles));
    }

    public function an(string ...$roles): bool
    {
        return $this->a(...$roles);
    }

    public function notA(string ...$roles): bool
    {
        return ! $this->a(...$roles);
    }

    public function notAn(string ...$roles): bool
    {
        return $this->notA(...$roles);
    }

    public function all(string ...$roles): bool
    {
        return $this->check('isAll', array_values($roles));
    }

    /**
     * @param  list<string>  $roles
     */
    private function check(string $method, array $roles): bool
    {
        if (! method_exists($this->authority, $method)) {
            // @codeCoverageIgnoreStart
            // Unreachable: the constructor already guarantees the concern.
            throw new InvalidArgumentException(
                'The authority must use the HasRolesAndPermissions concern to check roles.',
            );
            // @codeCoverageIgnoreEnd
        }

        return (bool) $this->authority->{$method}(...$roles);
    }
}
