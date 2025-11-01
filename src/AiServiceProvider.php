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

        $this->app->singleton(\Fomvasss\AiTasks\Support\WebhookRegistry::class, fn() => new \Fomvasss\AiTasks\Support\WebhookRegistry());
        $this->registerWebhookHandlerOpenAi();
        $this->registerWebhookHandlerGemini();
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

        \Illuminate\Support\Facades\Route::middleware(config('ai.webhook_middleware', ['api']))
            ->prefix('ai-webhooks')
            ->post('{driver}', [\Fomvasss\AiTasks\Http\Controllers\DynamicWebhookController::class, 'handle'])
            ->name('ai.webhooks.dynamic');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Fomvasss\AiTasks\Console\AiMakeTaskCommand::class,
                \Fomvasss\AiTasks\Console\AiRetryFailed::class,
                \Fomvasss\AiTasks\Console\AiRunsList::class,
                \Fomvasss\AiTasks\Console\AiBudgetCommand::class,
                \Fomvasss\AiTasks\Console\AiRequestCommand::class,
            ]);
        }
    }

    protected function registerWebhookHandlerOpenAi()
    {
        $this->app->afterResolving(\Fomvasss\AiTasks\Support\WebhookRegistry::class, function ($registry) {
            // OpenAI
            if (config('ai.drivers.openai')) {
                $registry->extend('openai', function (\Illuminate\Http\Request $r) {
                    $secret = config('ai.drivers.openai.webhook.secret');
                    $sigHdr = config('ai.drivers.openai.webhook.signature_header', 'X-OpenAI-Signature');

                    if ($secret) {
                        $sig = (string)$r->header($sigHdr);
                        $calc = hash_hmac('sha256', $r->getContent(), $secret);
                        abort_unless(hash_equals($calc, $sig), 401);
                    }

                    $event = $r->json()->all();
                    $providerRunId = data_get($event, 'data.id') ?? data_get($event, 'id');
                    $status = data_get($event, 'data.status', 'succeeded');
                    $output = data_get($event, 'data.output');
                    $usage = (array)data_get($event, 'data.usage', []);

                    return new \Fomvasss\AiTasks\DTO\WebhookPayload(
                        providerRunId: (string)$providerRunId,
                        status: $status,
                        content: $output,
                        usage: $usage,
                        error: data_get($event, 'data.error.message')
                    );
                });
            }
        });
    }

    protected function registerWebhookHandlerGemini()
    {
        $this->app->afterResolving(\Fomvasss\AiTasks\Support\WebhookRegistry::class, function ($registry) {
            if (config('ai.drivers.gemini')) {
                $registry->extend('gemini', function (\Illuminate\Http\Request $r) {
                    $secret = config('ai.drivers.gemini.webhook.secret');
                    $sigHdr = config('ai.drivers.gemini.webhook.signature_header', 'X-Gemini-Signature');

                    if ($secret) {
                        $sig = (string)$r->header($sigHdr);
                        $calc = hash_hmac('sha256', $r->getContent(), $secret);
                        abort_unless(hash_equals($calc, $sig), 401);
                    }

                    $event = $r->json()->all();
                    $providerRunId = data_get($event, 'id') ?? data_get($event, 'run.id');
                    $status = data_get($event, 'status', 'succeeded');
                    $output = data_get($event, 'result');
                    $usage = (array)data_get($event, 'usage', []);

                    return new \Fomvasss\AiTasks\DTO\WebhookPayload(
                        providerRunId: (string)$providerRunId,
                        status: $status,
                        content: $output,
                        usage: $usage,
                        error: data_get($event, 'error.message')
                    );
                });
            }
        });
    }
}
