<?php

namespace Fomvasss\AiTasks;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Core\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai.php', 'ai');

        $this->app->singleton(AiManager::class, fn($app) => new AiManager($app));
        $this->app->singleton(Router::class, fn() => new Router());
        $this->app->singleton(\Fomvasss\AiTasks\Support\TenantResolver::class, fn() => new \Fomvasss\AiTasks\Support\TenantResolver());

        $this->app->singleton(\Fomvasss\AiTasks\Core\AI::class, fn($app) => new \Fomvasss\AiTasks\Core\AI(
            $app->make(\Fomvasss\AiTasks\Core\AiManager::class),
            $app->make(\Fomvasss\AiTasks\Core\Router::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai.php' => config_path('ai.php'),
        ], 'config');

        if (! class_exists('CreateAiRunsTable')) {
            $this->publishes([
                __DIR__.'/../database/migrations/2025_10_19_000000_create_ai_runs_table.php'
                => database_path('migrations/'.date('Y_m_d_His').'_create_ai_runs_table.php'),
            ], 'migrations');
        }

        // Вебхук (опційно)
        Route::middleware(config('ai.webhook_middleware', ['api']))
            ->prefix('ai-webhooks')
            ->group(function () {
                Route::post('/openai', [Http\Controllers\WebhooksController::class, 'openai'])->name('ai.webhook.openai');
                Route::post('/gemini', [Http\Controllers\WebhooksController::class, 'gemini'])->name('ai.webhook.gemini');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Fomvasss\AiTasks\Console\AiMakeTaskCommand::class,
                \Fomvasss\AiTasks\Console\AiRetryFailed::class,
                \Fomvasss\AiTasks\Console\AiRunsList::class,
                \Fomvasss\AiTasks\Console\AiTestCommand::class,
            ]);
        }
    }
}
