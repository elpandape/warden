<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
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
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'bouncer');

        // Wait until every provider booted so app-level config overrides land first.
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
        $context = $this->app->make(Context::class);

        foreach (['permission', 'role'] as $key) {
            $alias = $context->morphAlias($key);

            if ($alias !== null) {
                Relation::morphMap([$alias => $context->modelClass($key)]);
            }
        }
    }
}
