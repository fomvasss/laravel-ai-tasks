<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\Http\Controllers\DashboardController;
use Fomvasss\AiTasks\Http\Controllers\WebhookController;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Support\TenantResolver;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai.php', 'ai');

        $this->app->singleton(AiManager::class, fn($app) => new AiManager($app));
        $this->app->singleton(Router::class, fn() => new Router());
        $this->app->singleton(TenantResolver::class, fn() => new TenantResolver());
        $this->app->singleton(Budget::class, fn() => new Budget());
        $this->app->singleton(WebhookRegistry::class, fn() => new WebhookRegistry());

        $this->app->singleton(AI::class, fn($app) => new AI(
            $app->make(AiManager::class),
            $app->make(Router::class),
        ));

        $this->registerWebhookHandlers();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ai-tasks');

        $this->publishes([
            __DIR__ . '/../config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/ai-tasks'),
        ], 'ai-views');

        if (! class_exists('CreateAiRunsTable')) {
            $this->publishes([
                __DIR__ . '/../database/migrations/2025_10_19_000000_create_ai_runs_table.php'
                    => database_path('migrations/' . date('Y_m_d_His') . '_create_ai_runs_table.php'),
            ], 'ai-migrations');
        }

        if (config('ai.dashboard.enabled', true)) {
            Route::middleware(config('ai.dashboard.middleware', ['web']))
                ->prefix(config('ai.dashboard.path', 'ai-tasks'))
                ->name('ai-tasks.')
                ->group(function () {
                    Route::get('/', [DashboardController::class, 'index'])->name('index');
                    Route::get('/{id}', [DashboardController::class, 'show'])->name('show');
                });
        }

        Route::middleware(config('ai.webhook_middleware', ['api']))
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

    private function registerWebhookHandlers(): void
    {
        $this->app->afterResolving(WebhookRegistry::class, function (WebhookRegistry $registry) {
            if (config('ai.drivers.openai')) {
                $registry->extend('openai', function (\Illuminate\Http\Request $r) {
                    $secret = config('ai.drivers.openai.webhook.secret');
                    $sigHdr = config('ai.drivers.openai.webhook.signature_header', 'X-OpenAI-Signature');

                    if ($secret) {
                        $sig  = (string) $r->header($sigHdr);
                        $calc = hash_hmac('sha256', $r->getContent(), $secret);
                        abort_unless(hash_equals($calc, $sig), 401);
                    }

                    $event = $r->json()->all();

                    return new \Fomvasss\AiTasks\DTO\WebhookPayload(
                        providerRunId: (string) (data_get($event, 'data.id') ?? data_get($event, 'id')),
                        status:        data_get($event, 'data.status', 'succeeded'),
                        content:       data_get($event, 'data.output'),
                        usage:         (array) data_get($event, 'data.usage', []),
                        error:         data_get($event, 'data.error.message'),
                    );
                });
            }
        });
    }
}
