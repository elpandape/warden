<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

use ElPandaPe\Bouncer\Database\AssignedRole;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Database\Permission;
use ElPandaPe\Bouncer\Database\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
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

            $this->assertOverrideContract($key, $override);

            return $override;
        }

        if ($key === 'user') {
            return $this->resolveUserModel();
        }

        return self::DEFAULT_MODELS[$key]
            ?? throw new InvalidArgumentException("Unknown bouncer model key [{$key}].");
    }

    /**
     * @return class-string<Permission>
     */
    public function permissionClass(): string
    {
        /** @var class-string<Permission> */
        return $this->modelClass('permission');
    }

    /**
     * @return class-string<Role>
     */
    public function roleClass(): string
    {
        /** @var class-string<Role> */
        return $this->modelClass('role');
    }

    /**
     * @return class-string<Grant>
     */
    public function grantClass(): string
    {
        /** @var class-string<Grant> */
        return $this->modelClass('grant');
    }

    /**
     * @return class-string<AssignedRole>
     */
    public function assignedRoleClass(): string
    {
        /** @var class-string<AssignedRole> */
        return $this->modelClass('assigned_role');
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
     * Fail at the config boundary, not deep inside a grant path.
     *
     * @param  class-string<Model>  $override
     */
    private function assertOverrideContract(string $key, string $override): void
    {
        $satisfied = match ($key) {
            'permission' => is_subclass_of($override, Permission::class)
                || in_array(Database\Concerns\IsPermission::class, class_uses_recursive($override), true),
            'role' => is_subclass_of($override, Role::class)
                || in_array(Database\Concerns\IsRole::class, class_uses_recursive($override), true),
            'grant', 'assigned_role' => is_subclass_of($override, MorphPivot::class),
            default => true,
        };

        if (! $satisfied) {
            throw new InvalidArgumentException(
                "Configured bouncer model [{$key}] ([{$override}]) does not satisfy the {$key} contract.",
            );
        }
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
