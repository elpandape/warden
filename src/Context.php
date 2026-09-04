<?php

declare(strict_types=1);

namespace ElPandaPe\Warden;

use Closure;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

final class Context
{
    private const array DEFAULT_MODELS = [
        'permission' => Permission::class,
        'role' => Role::class,
        'assigned_role' => AssignedRole::class,
        'grant' => Grant::class,
    ];

    /** @var array<string, string|Closure> */
    private array $ownershipMap = [];

    /** @var array<string, true> */
    private array $unownedClasses = [];

    /** @var array<string, string|Closure> */
    private array $restrictionMap = [];

    /**
     * @param  array<string, string>  $tables
     * @param  array<string, string>  $morphAliases
     * @param  array<string, string>  $modelOverrides
     */
    public function __construct(
        private array $tables = [],
        private ?string $connection = null,
        private array $morphAliases = [],
        private readonly ?string $ownershipAttribute = 'user_id',
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
            // An explicit null means unregistered models have no owner at all.
            // A missing key, or a value that is not a name, means the default.
            ownershipAttribute: array_key_exists('default_attribute', $ownership) && $ownership['default_attribute'] === null
                ? null
                : self::stringOrNull($ownership['default_attribute'] ?? null) ?? 'user_id',
            modelOverrides: self::stringMap($config['models'] ?? null),
        );
    }

    public static function resolve(): self
    {
        return app(self::class);
    }

    public function table(string $name): string
    {
        $table = $this->tables[$name] ?? $name;

        // Fail fast: an empty name would otherwise surface later as broken
        // SQL with an empty identifier, far from the misconfiguration.
        if ($table === '') {
            throw new ConfigurationException("Configured warden table [{$name}] must not be empty.");
        }

        return $table;
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

    /**
     * The default ownership attribute. Frozen as non-nullable at 1.0, so when
     * ownership is switched off it reports an empty name: ask
     * resolvesOwnershipFor() before trusting it.
     */
    public function ownershipAttribute(): string
    {
        return $this->ownershipAttribute ?? '';
    }

    /**
     * Register how ownership is resolved: globally, per entity class,
     * by attribute name or with a closure receiving (entity, authority).
     */
    public function ownedVia(string|Closure $modelOrAttribute, string|Closure|null $attribute = null): void
    {
        if ($attribute === null) {
            $this->ownershipMap['*'] = $modelOrAttribute;

            return;
        }

        if (! is_string($modelOrAttribute)) {
            throw new ConfigurationException('Per-model ownership requires the entity class as a string.');
        }

        $this->ownershipMap[$modelOrAttribute] = $attribute;
    }

    /**
     * Declare that ownership means nothing for this class, overriding the
     * global fallback. ownedVia() can only register a resolver, never remove
     * one: passing null there sets the global attribute instead.
     */
    public function notOwned(string $class): void
    {
        $this->unownedClasses[$class] = true;
    }

    /**
     * Configure how a checked entity is matched against a role's restriction
     * context: a global attribute/closure, or one per context class.
     */
    public function restrictedVia(string|Closure $contextOrAttribute, string|Closure|null $attribute = null): void
    {
        if ($attribute === null) {
            $this->restrictionMap['*'] = $contextOrAttribute;

            return;
        }

        if ($contextOrAttribute instanceof Closure) {
            throw new ConfigurationException('Per-model restriction requires the context class as a string.');
        }

        $this->restrictionMap[$contextOrAttribute] = $attribute;
    }

    /**
     * Whether the checked entity belongs to a restricted role's context:
     * it either IS the context, or points at it through an attribute
     * (convention: {context_basename}_id), or a configured closure decides.
     */
    public function belongsToContext(Model $entity, string $contextType, int|string $contextId): bool
    {
        if ($entity->getMorphClass() === $contextType) {
            $key = $entity->getKey();

            if ((is_int($key) || is_string($key)) && (string) $key === (string) $contextId) {
                return true;
            }
        }

        $class = Relation::getMorphedModel($contextType) ?? $contextType;

        $resolver = $this->restrictionResolverFor($class);

        if ($resolver instanceof Closure) {
            $context = is_subclass_of($class, Model::class)
                ? $class::query()->find($contextId)
                : null;

            return $context instanceof Model && (bool) $resolver($entity, $context);
        }

        $value = $this->attributeValue($entity, $resolver);

        return (is_int($value) || is_string($value)) && (string) $value === (string) $contextId;
    }

    public function isOwnedBy(Model $authority, Model $entity): bool
    {
        if (! $this->resolvesOwnershipFor($entity)) {
            return false;
        }

        $resolver = $this->ownershipResolverFor($entity);

        if ($resolver instanceof Closure) {
            return (bool) $resolver($entity, $authority);
        }

        // Strict-mode safe (configurable): a missing attribute means "not owned".
        if (! array_key_exists($resolver, $entity->getAttributes())) {
            if (Support\Config::ownershipStrictModeSafe()) {
                return false;
            }

            // Opted out: surface whatever the model does, including strict-mode throws.
            $entity->getAttribute($resolver);

            return false;
        }

        $value = $entity->getAttributes()[$resolver];
        $key = $authority->getKey();

        if ($value === null || (! is_int($key) && ! is_string($key))) {
            return false;
        }

        return (is_int($value) || is_string($value)) && (string) $value === (string) $key;
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(string $key): string
    {
        $override = $this->modelOverrides[$key] ?? null;

        if ($override !== null) {
            if (! is_subclass_of($override, Model::class)) {
                throw new ConfigurationException(
                    "Configured warden model [{$key}] must be an Eloquent model class, [{$override}] given.",
                );
            }

            $this->assertOverrideContract($key, $override);

            return $override;
        }

        if ($key === 'user') {
            return $this->resolveUserModel();
        }

        return self::DEFAULT_MODELS[$key]
            ?? throw new ConfigurationException("Unknown warden model key [{$key}].");
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
     * How a role restriction resolves for a context class: an attribute name,
     * or a closure. Takes a class or a morph alias, because the caller usually
     * holds a stored restricted_to_type and no instance.
     */
    public function restrictionResolverFor(string $contextClass): string|Closure
    {
        $class = Relation::getMorphedModel($contextClass) ?? $contextClass;

        return $this->restrictionMap[$class]
            ?? $this->restrictionMap['*']
            ?? Support\Config::restrictionsDefaultAttribute()
            ?? Str::snake(class_basename($class)).'_id';
    }

    /**
     * How ownership resolves for this entity's class: an attribute name, or
     * a closure that SQL compilation cannot express.
     */
    public function ownershipResolverFor(Model $entity): string|Closure
    {
        return $this->ownershipMap[$entity::class]
            ?? $this->ownershipMap['*']
            ?? $this->ownershipAttribute
            ?? '';
    }

    /**
     * Whether ownership means anything for this class.
     *
     * The resolver above cannot answer it: its return type is frozen at 1.0 and
     * has no way to say "none", so every model looks owned whether or not it is.
     */
    public function resolvesOwnershipFor(Model|string $entity): bool
    {
        $class = $entity instanceof Model ? $entity::class : $entity;

        return ! isset($this->unownedClasses[$class])
            && (isset($this->ownershipMap[$class])
                || isset($this->ownershipMap['*'])
                || $this->ownershipAttribute !== null);
    }

    /**
     * Strict-mode safe attribute read (configurable): missing means null.
     */
    public function attributeValue(Model $model, string $attribute): mixed
    {
        if (! array_key_exists($attribute, $model->getAttributes())) {
            if (Support\Config::ownershipStrictModeSafe()) {
                return null;
            }

            // Opted out: surface whatever the model does, including strict-mode throws.
            $model->getAttribute($attribute);

            return null;
        }

        return $model->getAttributes()[$attribute];
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

        throw new ConfigurationException(
            'Unable to resolve the user model from the default auth guard; set warden.models.user explicitly.',
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
                || in_array(Models\Concerns\IsPermission::class, class_uses_recursive($override), true),
            'role' => is_subclass_of($override, Role::class)
                || in_array(Models\Concerns\IsRole::class, class_uses_recursive($override), true),
            'grant', 'assigned_role' => is_subclass_of($override, MorphPivot::class),
            default => true,
        };

        if (! $satisfied) {
            throw new ConfigurationException(
                "Configured warden model [{$key}] ([{$override}]) does not satisfy the {$key} contract.",
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
