<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\Http\Controllers\DashboardController;
use Fomvasss\AiTasks\Http\Controllers\WebhookController;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Support\ModelLister;
use Fomvasss\AiTasks\Support\TenantResolver;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    private static int $migrationTimestampOffset = 0;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-tasks.php', 'ai-tasks');

        $this->app->singleton(AiManager::class, fn($app) => new AiManager($app));
        $this->app->singleton(Router::class, fn() => new Router());
        $this->app->singleton(Budget::class, fn() => new Budget());
        $this->app->singleton(WebhookRegistry::class, fn() => new WebhookRegistry());
        $this->app->singleton(ModelLister::class, fn() => new ModelLister());

        // scoped: new instance per request/job — safe for Octane and custom stateful resolvers
        $this->app->scoped(TenantResolver::class, fn() => new TenantResolver());

        $this->app->singleton(AI::class, fn($app) => new AI(
            $app->make(AiManager::class),
            $app->make(Router::class),
            $app->make(ModelLister::class),
        ));

        $this->registerOctaneListeners();

        $this->registerWebhookHandlers();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ai-tasks');

        $this->publishes([
            __DIR__ . '/../config/ai-tasks.php' => config_path('ai-tasks.php'),
        ], 'ai-tasks-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/ai-tasks'),
        ], 'ai-views');

        if ($this->app->runningInConsole()) {
            $this->publishMigrationOnceMissing(
                __DIR__ . '/../database/migrations/2025_10_19_000000_create_ai_runs_table.php',
                'create_ai_runs_table',
            );

            $this->publishMigrationOnceMissing(
                __DIR__ . '/../database/migrations/2026_07_29_000000_add_model_to_ai_runs_table.php',
                'add_model_to_ai_runs_table',
            );
        }

        if (config('ai-tasks.dashboard.enabled', true)) {
            Route::middleware(config('ai-tasks.dashboard.middleware', ['web']))
                ->prefix(config('ai-tasks.dashboard.path', 'ai-tasks'))
                ->name('ai-tasks.')
                ->group(function () {
                    Route::get('/', [DashboardController::class, 'index'])->name('index');
                    Route::get('/data', [DashboardController::class, 'data'])->name('data');
                    Route::get('/{id}', [DashboardController::class, 'show'])->name('show');
                    // Write actions run under the same middleware as the dashboard itself — which
                    // defaults to ['web'], i.e. no authorization. Add your own auth there.
                    Route::post('/{id}/retry', [DashboardController::class, 'retry'])->name('retry');
                    Route::post('/{id}/dead', [DashboardController::class, 'markDead'])->name('dead');
                });
        }

        Route::middleware(config('ai-tasks.webhook_middleware', ['api']))
            ->prefix('ai-webhooks')
            ->post('{driver}', [WebhookController::class, 'handle'])
            ->name('ai.webhooks');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\AiMakeTaskCommand::class,
                Console\AiModelsCommand::class,
                Console\AiRetryFailed::class,
                Console\AiRunsList::class,
                Console\AiBudgetCommand::class,
                Console\AiRequestCommand::class,
            ]);
        }
    }

    /**
     * Publishes a package migration under a fresh timestamp, unless a file for it
     * (matched by its base name, regardless of the timestamp prefix used when it was
     * originally published) already exists in the app's migrations directory.
     */
    private function publishMigrationOnceMissing(string $source, string $baseName): void
    {
        if (glob(database_path("migrations/*_{$baseName}.php")) !== []) {
            return;
        }

        $timestamp = now()->addSeconds(self::$migrationTimestampOffset++)->format('Y_m_d_His');

        $this->publishes([
            $source => database_path("migrations/{$timestamp}_{$baseName}.php"),
        ], 'ai-migrations');
    }

    private function registerOctaneListeners(): void
    {
        if (! class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        $flush = function (): void {
            $this->app->make(AiManager::class)->forgetDrivers();
            $this->flushCustomProviderAliases();
        };

        $this->app['events']->listen(\Laravel\Octane\Events\RequestReceived::class, $flush);
        $this->app['events']->listen(\Laravel\Octane\Events\TaskReceived::class, $flush);
    }

    /**
     * Removes the runtime-registered custom_* provider aliases (created per providerOverride
     * call in the driver) from config and from laravel/ai's instance cache — on Octane both
     * survive between requests and would otherwise accumulate one entry per unique
     * driver+key pair for the worker's lifetime.
     */
    private function flushCustomProviderAliases(): void
    {
        $providers = config('ai.providers', []);
        $aiManager = $this->app->bound(\Laravel\Ai\AiManager::class)
            ? $this->app->make(\Laravel\Ai\AiManager::class)
            : null;

        foreach (array_keys($providers) as $name) {
            if (str_starts_with((string) $name, 'custom_')) {
                unset($providers[$name]);
                $aiManager?->forgetInstance($name);
            }
        }

        config(['ai.providers' => $providers]);
    }

    private function registerWebhookHandlers(): void
    {
        $this->app->afterResolving(WebhookRegistry::class, function (WebhookRegistry $registry) {
            if (config('ai-tasks.drivers.openai')) {
                $registry->extend('openai', function (\Illuminate\Http\Request $r) {
                    $secret = config('ai-tasks.drivers.openai.webhook.secret');

                    if ($secret) {
                        abort_unless(\Fomvasss\AiTasks\Support\StandardWebhookVerifier::verify($r, $secret), 401);
                    }

                    $event = $r->json()->all();

                    return new \Fomvasss\AiTasks\DTO\WebhookPayload(
                        providerRunId: (string) (data_get($event, 'data.id') ?? data_get($event, 'id')),
                        status: data_get($event, 'data.status', 'succeeded'),
                        content: data_get($event, 'data.output'),
                        usage: (array) data_get($event, 'data.usage', []),
                        error: data_get($event, 'data.error.message'),
                    );
                });
            }
        });
    }
}
