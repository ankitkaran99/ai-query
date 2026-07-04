<?php

namespace Scholar\AiQuery;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Scholar\AiQuery\Console\ClearCacheCommand;
use Scholar\AiQuery\Support\QueryBuilder;
use Scholar\AiQuery\Support\QueryableDefinition;
use Scholar\AiQuery\Support\QuerySpecValidator;
use Scholar\AiQuery\Support\SpecCache;
use Scholar\AiQuery\Support\SpecGenerator;
use Throwable;

final class AiQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-query.php', 'ai-query');

        $this->app->singleton(QueryableRegistry::class);

        $this->app->singleton(AiQueryService::class, function ($app) {
            $config = $app['config']->get('ai-query');
            $registry = $app->make(QueryableRegistry::class);

            return new AiQueryService(
                registry: $registry,
                generator: new SpecGenerator($registry, $config),
                validator: new QuerySpecValidator(),
                builder: new QueryBuilder(),
                cache: new SpecCache($config['cache']),
                config: $config,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai-query.php' => config_path('ai-query.php'),
        ], 'ai-query-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ClearCacheCommand::class]);
        }

        $this->discoverQueryables();
    }

    /**
     * Scan app/AiQuery (configurable) for classes extending Queryable and
     * register them automatically. Runs on every request (boot() isn't
     * conditional), so each class is resolved defensively: a single bad
     * definition — a typo'd relation name, a model that isn't migrated yet
     * on a fresh install — must not be able to 500 every page in the app
     * just because it's unrelated to whatever page is loading.
     */
    private function discoverQueryables(): void
    {
        $config = $this->app['config']->get('ai-query.discovery');

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $path = $config['path'] ?? app_path('AiQuery');

        if (! is_dir($path)) {
            return;
        }

        $registry = $this->app->make(QueryableRegistry::class);
        $namespace = rtrim($this->app->getNamespace(), '\\') . '\\AiQuery\\';

        foreach (glob($path . '/*.php') ?: [] as $file) {
            $class = $namespace . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Queryable::class)) {
                continue;
            }

            try {
                // BUG FIX: newInstance() bypassed the container, so a
                // Queryable class with constructor dependencies (a
                // repository, the current tenant, anything typehinted)
                // would fail. make() resolves it the normal Laravel way.
                $queryable = $this->app->make($class);

                $registry->add(QueryableDefinition::fromQueryable($queryable));
            } catch (Throwable $e) {
                // BUG FIX: previously uncaught — one broken queryable
                // (bad relation name, model not ready on a fresh install)
                // would throw out of boot() and take down every request
                // in the app, not just AI Query. Log and skip it instead;
                // AiQueryService::ask() will still throw a clear
                // UnknownQueryableException if someone actually tries to
                // use the one that failed to register.
                report($e);
            }
        }
    }
}
