<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Support\Name;
use Illuminate\Database\Eloquent\Model;

class ChecksRoles
{
    public function __construct(private readonly Model $authority)
    {
        // A same-named method on an unrelated model must not answer authorization checks.
        if (! in_array(HasRolesAndPermissions::class, class_uses_recursive($authority), true)) {
            throw new ConfigurationException(
                'The authority must use the HasRolesAndPermissions concern to check roles.',
            );
        }
    }

    public function a(string|BackedEnum ...$roles): bool
    {
        return $this->check('isA', array_map(Name::of(...), array_values($roles)));
    }

    public function an(string|BackedEnum ...$roles): bool
    {
        return $this->a(...$roles);
    }

    public function notA(string|BackedEnum ...$roles): bool
    {
        return ! $this->a(...$roles);
    }

    public function notAn(string|BackedEnum ...$roles): bool
    {
        return $this->notA(...$roles);
    }

    public function all(string|BackedEnum ...$roles): bool
    {
        return $this->check('isAll', array_map(Name::of(...), array_values($roles)));
    }

    /**
     * @param  list<string>  $roles
     */
    private function check(string $method, array $roles): bool
    {
        if (! method_exists($this->authority, $method)) {
            // @codeCoverageIgnoreStart
            // Unreachable: the constructor already guarantees the concern.
            throw new ConfigurationException(
                'The authority must use the HasRolesAndPermissions concern to check roles.',
            );
            // @codeCoverageIgnoreEnd
        }

        $result = $this->authority->{$method}(...$roles);

        return is_bool($result) && $result;
    }
}
