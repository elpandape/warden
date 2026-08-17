<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

final readonly class Verdict
{
    private function __construct(
        private bool $granted,
        private bool $forbidden,
        public int|string|null $permissionKey,
    ) {}

    public static function granted(int|string $permissionKey): self
    {
        return new self(granted: true, forbidden: false, permissionKey: $permissionKey);
    }

    public static function forbidden(int|string|null $permissionKey = null): self
    {
        return new self(granted: false, forbidden: true, permissionKey: $permissionKey);
    }

    public static function abstained(): self
    {
        return new self(granted: false, forbidden: false, permissionKey: null);
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
