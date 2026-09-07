<?php

declare(strict_types=1);

namespace ElPandaPe\Warden;

use ElPandaPe\Warden\Checks\GateRegistrar;
use ElPandaPe\Warden\Support\Config;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class WardenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warden.php', 'warden');

        $this->app->singleton(Context::class, function (Application $app): Context {
            $config = $app->make(Repository::class)->get('warden');

            return Context::fromConfig(is_array($config) ? $config : []);
        });

        $this->app->singleton(Warden::class);

        // Scoped: who is acting changes between Octane requests and queue jobs.
        $actor = function (Application $app): Contracts\ActorResolver {
            $resolver = $app->make(Repository::class)->get('warden.actor_resolver');

            $instance = is_string($resolver) && is_subclass_of($resolver, Contracts\ActorResolver::class)
                ? $app->make($resolver)
                : null;

            return $instance instanceof Contracts\ActorResolver ? $instance : new Events\Actors\AuthenticatedActor;
        };

        if ((bool) $this->app->make(Repository::class)->get('warden.octane.register_reset_listener', true)) {
            $this->app->scoped(Contracts\ActorResolver::class, $actor);
        } else {
            $this->app->singleton(Contracts\ActorResolver::class, $actor);
        }

        $tenancy = function (Application $app): Tenancy\Tenancy {
            $resolver = $app->make(Repository::class)->get('warden.scope.tenant_resolver');

            $instance = is_string($resolver) && is_subclass_of($resolver, Contracts\TenantResolver::class)
                ? $app->make($resolver)
                : null;

            return new Tenancy\Tenancy(
                $instance instanceof Contracts\TenantResolver ? $instance : null,
            );
        };

        // Scoped: tenant state resets between Octane requests and queue jobs.
        if ((bool) $this->app->make(Repository::class)->get('warden.octane.register_reset_listener', true)) {
            $this->app->scoped(Tenancy\Tenancy::class, $tenancy);
        } else {
            $this->app->singleton(Tenancy\Tenancy::class, $tenancy);
        }

        $this->app->singleton(Checks\Resolvers\CacheKeyVersioner::class);

        // Scoped: it holds per-request write state, so it resets between Octane
        // requests and queue jobs. The versioner beside it stays stateless.
        if ((bool) $this->app->make(Repository::class)->get('warden.octane.register_reset_listener', true)) {
            $this->app->scoped(Checks\Resolvers\CacheInvalidations::class);
        } else {
            $this->app->singleton(Checks\Resolvers\CacheInvalidations::class);
        }

        // The cached resolver decorates the database engine; the enabled flag
        // is honored live inside resolve(), so toggling needs no rebinding.
        $resolver = fn (Application $app): Contracts\Resolver => new Checks\Resolvers\CachedResolver(
            new Checks\Resolvers\DatabaseResolver($app->make(Context::class)),
            $app->make(Context::class),
            $app->make(Checks\Resolvers\CacheKeyVersioner::class),
        );

        // Scoped: per-request memoization resets between Octane requests and jobs.
        if ((bool) $this->app->make(Repository::class)->get('warden.octane.register_reset_listener', true)) {
            $this->app->scoped(Contracts\Resolver::class, $resolver);
        } else {
            $this->app->singleton(Contracts\Resolver::class, $resolver);
        }

        // Lazy: the gate wiring only happens when the app actually authorizes something.
        // The register toggle is evaluated per check inside the callbacks.
        $this->callAfterResolving(GateContract::class, function (GateContract $gate): void {
            new GateRegistrar()->registerAt($gate);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'warden');

        // Listened for from outside the models: the pivot classes are swappable
        // and warden's override contract asks only for a MorphPivot, so a trait
        // on warden's own pivots would miss exactly the consumer who customised.
        \Illuminate\Support\Facades\Event::listen([
            'eloquent.created: *',
            'eloquent.updated: *',
            'eloquent.deleting: *',
            'eloquent.deleted: *',
        ], function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model) {
                return; // @codeCoverageIgnore
            }

            $invalidations = $this->app->make(Checks\Resolvers\CacheInvalidations::class);

            if (str_starts_with($event, 'eloquent.deleting:')) {
                $invalidations->prepareCascade($model);

                return;
            }

            $invalidations->markFrom($model);

            if (str_starts_with($event, 'eloquent.deleted:')) {
                $invalidations->markCascade($model);
            }
        });

        // Optional borrowings, off by default: identity stays fluent-first.
        if (Config::registersMiddlewareAliases()) {
            $router = $this->app->make(\Illuminate\Routing\Router::class);
            $router->aliasMiddleware('warden.role', Http\Middleware\RequiresRole::class);
            $router->aliasMiddleware('warden.permission', Http\Middleware\RequiresPermission::class);
        }

        if (Config::registersBladeDirectives()) {
            // @forbidden('publish') — the answer only this data model has:
            // an explicit denial, distinct from a plain lack of permission.
            \Illuminate\Support\Facades\Blade::if('forbidden', function (string|\BackedEnum $permission, mixed $entity = null): bool {
                $user = auth()->user();

                if (! $user instanceof Model) {
                    return false;
                }

                $target = $entity instanceof Model || is_string($entity) ? $entity : null;

                return $this->app->make(Contracts\Resolver::class)
                    ->resolve($user, Support\Name::of($permission), $target)
                    ->isForbidden();
            });
        }

        // Register aliases now so writes during other providers' boot use them,
        // and re-sync after boot so app-level config overrides land too.
        $this->registerMorphAliases();
        $this->app->booted(function (): void {
            $this->registerMorphAliases();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\ShowCommand::class,
                Console\CacheResetCommand::class,
                Console\CleanCommand::class,
                Console\DoctorCommand::class,
                Console\UpgradeCommand::class,
                Console\RetitleCommand::class,
            ]);

            \Illuminate\Foundation\Console\AboutCommand::add('Warden', fn (): array => [
                'Version' => \Composer\InstalledVersions::getPrettyVersion('elpandape/warden') ?? 'dev',
                'Cache' => Config::cacheEnabled() ? 'enabled' : 'disabled',
                'Events' => Config::eventsEnabled() ? 'enabled' : 'disabled',
                'Tenancy null behavior' => Config::scopeNullBehavior(),
            ]);

            $this->publishes([
                __DIR__.'/../config/warden.php' => config_path('warden.php'),
            ], 'warden-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_warden_tables.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_warden_tables.php',
                ),
            ], 'warden-migrations');

            $this->publishes([
                __DIR__.'/../database/migrations/upgrade_warden_to_v2.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_upgrade_warden_to_v2.php',
                ),
            ], 'warden-migrations-v2');
        }
    }

    private function registerMorphAliases(): void
    {
        // Read config directly: resolving the Context here would freeze it too early.
        $config = $this->app->make(Repository::class);
        $aliases = $config->get('warden.morph_aliases');
        $models = $config->get('warden.models');

        // The alias carries the same in-code default the model already has: a
        // published config that dropped the key used to skip registration
        // entirely, and every stored row then pointed at a name nothing mapped.
        $defaults = [
            'permission' => ['alias' => 'warden.permission', 'model' => Models\Permission::class],
            'role' => ['alias' => 'warden.role', 'model' => Models\Role::class],
        ];

        foreach ($defaults as $key => $default) {
            $configured = is_array($aliases) ? ($aliases[$key] ?? null) : null;
            $alias = is_string($configured) ? $configured : $default['alias'];
            $model = is_array($models) ? ($models[$key] ?? null) : null;

            Relation::morphMap([
                $alias => is_string($model) && is_subclass_of($model, Model::class) ? $model : $default['model'],
            ]);
        }
    }
}
