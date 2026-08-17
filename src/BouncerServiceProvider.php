<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class BouncerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bouncer.php', 'bouncer');

        $this->app->singleton(Context::class, function (Application $app): Context {
            $config = $app->make(Repository::class)->get('bouncer');

            return Context::fromConfig(is_array($config) ? $config : []);
        });

        $this->app->singleton(Bouncer::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'bouncer');

        // Register aliases now so writes during other providers' boot use them,
        // and re-sync after boot so app-level config overrides land too.
        $this->registerMorphAliases();
        $this->app->booted(function (): void {
            $this->registerMorphAliases();
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bouncer.php' => config_path('bouncer.php'),
            ], 'bouncer-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_bouncer_tables.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_bouncer_tables.php',
                ),
            ], 'bouncer-migrations');
        }
    }

    private function registerMorphAliases(): void
    {
        // Read config directly: resolving the Context here would freeze it too early.
        $config = $this->app->make(Repository::class);
        $aliases = $config->get('bouncer.morph_aliases');
        $models = $config->get('bouncer.models');

        $defaults = [
            'permission' => Database\Permission::class,
            'role' => Database\Role::class,
        ];

        foreach ($defaults as $key => $default) {
            $alias = is_array($aliases) ? ($aliases[$key] ?? null) : null;
            $model = is_array($models) ? ($models[$key] ?? null) : null;

            if (is_string($alias)) {
                Relation::morphMap([
                    $alias => is_string($model) && is_subclass_of($model, Model::class) ? $model : $default,
                ]);
            }
        }
    }
}
