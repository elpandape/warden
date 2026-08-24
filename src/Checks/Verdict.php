<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks;

use Illuminate\Database\Eloquent\Model;

final readonly class Verdict
{
    /**
     * @param  list<int|string>  $rejectedKeys  candidates a condition turned down
     */
    private function __construct(
        private bool $granted,
        private bool $forbidden,
        public int|string|null $permissionKey,
        public array $rejectedKeys = [],
        public ?Model $permission = null,
    ) {}

    public static function granted(int|string $permissionKey, ?Model $permission = null): self
    {
        return new self(granted: true, forbidden: false, permissionKey: $permissionKey, permission: $permission);
    }

    public static function forbidden(int|string|null $permissionKey = null, ?Model $permission = null): self
    {
        return new self(granted: false, forbidden: true, permissionKey: $permissionKey, permission: $permission);
    }

    /**
     * @param  list<int|string>  $rejectedKeys
     */
    public static function abstained(array $rejectedKeys = [], ?Model $rejected = null): self
    {
        return new self(granted: false, forbidden: false, permissionKey: null, rejectedKeys: $rejectedKeys, permission: $rejected);
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function isForbidden(): bool
    {
        return $this->forbidden;
    }

    public function isAbstained(): bool
    {
        return ! $this->granted && ! $this->forbidden;
    }
}
