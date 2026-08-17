<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

final class Context
{
    /**
     * @param  array<string, string>  $tables
     * @param  array<string, string>  $morphAliases
     */
    private function __construct(
        private array $tables,
        private ?string $connection,
        private array $morphAliases,
        private readonly string $ownershipAttribute,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $ownership = is_array($config['ownership'] ?? null) ? $config['ownership'] : [];

        return new self(
            tables: self::stringMap($config['tables'] ?? null),
            connection: self::stringOrNull($config['connection'] ?? null),
            morphAliases: self::stringMap($config['morph_aliases'] ?? null),
            ownershipAttribute: self::stringOrNull($ownership['default_attribute'] ?? null) ?? 'user_id',
        );
    }

    public function table(string $name): string
    {
        return $this->tables[$name] ?? $name;
    }

    public function setTable(string $name, string $table): void
    {
        $this->tables[$name] = $table;
    }

    public function connection(): ?string
    {
        return $this->connection;
    }

    public function setConnection(?string $connection): void
    {
        $this->connection = $connection;
    }

    public function morphAlias(string $key): ?string
    {
        return $this->morphAliases[$key] ?? null;
    }

    public function ownershipAttribute(): string
    {
        return $this->ownershipAttribute;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
