<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

use ElPandaPe\Bouncer\Database\AssignedRole;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Database\Permission;
use ElPandaPe\Bouncer\Database\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

final class Context
{
    private const array DEFAULT_MODELS = [
        'permission' => Permission::class,
        'role' => Role::class,
        'assigned_role' => AssignedRole::class,
        'grant' => Grant::class,
    ];

    /**
     * @param  array<string, string>  $tables
     * @param  array<string, string>  $morphAliases
     * @param  array<string, string>  $modelOverrides
     */
    public function __construct(
        private array $tables = [],
        private ?string $connection = null,
        private array $morphAliases = [],
        private readonly string $ownershipAttribute = 'user_id',
        private array $modelOverrides = [],
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
            modelOverrides: self::stringMap($config['models'] ?? null),
        );
    }

    public static function resolve(): self
    {
        return app(self::class);
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
     * @return class-string<Model>
     */
    public function modelClass(string $key): string
    {
        $override = $this->modelOverrides[$key] ?? null;

        if ($override !== null) {
            if (! is_subclass_of($override, Model::class)) {
                throw new InvalidArgumentException(
                    "Configured bouncer model [{$key}] must be an Eloquent model class, [{$override}] given.",
                );
            }

            return $override;
        }

        if ($key === 'user') {
            return $this->resolveUserModel();
        }

        return self::DEFAULT_MODELS[$key]
            ?? throw new InvalidArgumentException("Unknown bouncer model key [{$key}].");
    }

    public function setModelClass(string $key, string $class): void
    {
        $this->modelOverrides[$key] = $class;

        // Keep the morph alias in sync when models are swapped after boot.
        $alias = $this->morphAlias($key);

        if ($alias !== null) {
            Relation::morphMap([$alias => $this->modelClass($key)]);
        }
    }

    /**
     * @return class-string<Model>
     */
    private function resolveUserModel(): string
    {
        $guard = config('auth.defaults.guard');
        $provider = is_string($guard) ? config("auth.guards.{$guard}.provider") : null;
        $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            return $model;
        }

        throw new InvalidArgumentException(
            'Unable to resolve the user model from the default auth guard; set bouncer.models.user explicitly.',
        );
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
