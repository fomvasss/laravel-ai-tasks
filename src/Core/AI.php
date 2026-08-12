<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Events\AiTaskCompletedHandlerFailed;
use Fomvasss\AiTasks\Events\AiTaskFailed;
use Fomvasss\AiTasks\Events\AiTaskQueued;
use Fomvasss\AiTasks\Events\AiTaskStarted;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Support\ModelLister;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Tasks\PromptTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;

class AI
{
    public function __construct(
        private readonly AiManager   $manager,
        private readonly Router      $router,
        private readonly ModelLister $lister = new ModelLister(),
    ) {}

    /**
     * List available models for a driver, straight from the provider's own API
     * (not config/ai-tasks.php). Credentials come from config/ai.php, same as
     * send()/queue()/stream().
     *
     * @return array<int, array{id: string, display_name: ?string, owner: ?string, created: ?string, context_in: ?int, context_out: ?int, methods: ?string, capabilities: ?string}>
     *
     * @throws AiDriverException when the driver has no api_key configured in config/ai.php
     * @throws \Fomvasss\AiTasks\Exceptions\ModelListingUnavailableException when the driver has no listing endpoint available
     * @throws \Fomvasss\AiTasks\Exceptions\ModelListingException on connection or API errors
     */
    public function models(string $driver, ?string $filter = null): array
    {
        $apiKey = config("ai.providers.{$driver}.key")
            ?? config("ai.providers.{$driver}.access_key_id"); // bedrock

        if (empty($apiKey)) {
            throw new AiDriverException("Driver [{$driver}] is not configured in config/ai.php.");
        }

        $cfg = config("ai-tasks.drivers.{$driver}", []);
        $cfg['api_key'] = $apiKey;

        return $this->lister->forDriver($driver, $cfg, $filter);
    }

    /**
     * Quick one-off text prompt, without writing a dedicated AiTask class.
     * Still goes through send() — routing, budget checks and AiRun tracking apply
     * as usual, grouped in the dashboard under the given (or default 'prompt') name.
     */
    public function prompt(string $prompt, ?string $system = null, array|string $drivers = [], string $name = 'prompt'): AiResponse
    {
        return $this->send(new PromptTask($prompt, $system, $name), $drivers);
    }

    public function send(AiTask $task, array|string $drivers = []): AiResponse
    {
        $payload = self::payloadWithTools($task);
        $ctx     = $task->context();

        app(Budget::class)->ensureNotExceeded($ctx->tenantId);

        $list   = $this->resolveDrivers($task, $drivers);
        $errors = [];

        foreach ($list as $driverName) {
            $run = AiRun::start($driverName, $payload, $ctx, $task);

            if (! $this->isConfigured($driverName) && ! ($payload->providerOverride['key'] ?? null)) {
                $run->skip("driver_not_configured: {$driverName}");
                continue;
            }

            event(new AiTaskStarted($task, $ctx, $run));

            try {
                $resp = $this->manager->driver($driverName)->send($payload, $ctx);

                if (! $resp->ok) {
                    $run->fail($resp->error ?? 'unknown_error');
                    event(new AiTaskFailed($task, $resp->error ?? 'unknown_error', $run));
                    $errors[] = "{$driverName}: {$resp->error}";
                    continue;
                }

                app(Budget::class)->ensureNotExceeded($ctx->tenantId, (float) ($resp->usage['cost'] ?? 0.0));
            } catch (BudgetExceededException $e) {
                // Виклик провайдера вже відбувся й оплачений — зберігаємо cost через finish(),
                // інакше витрата випадає з майбутніх підрахунків бюджету (issue #7).
                $run->finish($resp);
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                throw $e;
            } catch (\Throwable $e) {
                $run->fail($e->getMessage());
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                $errors[] = "{$driverName}: {$e->getMessage()}";
                continue;
            }

            $run->finish($resp);

            $resp = $this->runPostprocess($resp);
            $result = $task->postprocess($resp);

            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            self::complete($task, $result, $finalResponse, $run);

            return $finalResponse;
        }

        throw new AiDriverException('All providers failed: ' . implode(' | ', $errors));
    }

    public function queue(AiTask $task, array|string $drivers = [], \DateTimeInterface|\DateInterval|int|null $delay = null): string
    {
        $ctor = (new \ReflectionClass($task))->getConstructor();
        if ($ctor && $ctor->getNumberOfRequiredParameters() > 0 && empty($task->serializeForQueue())) {
            throw new \LogicException(
                $task::class . ' has constructor parameters but serializeForQueue() returns []. ' .
                'Implement serializeForQueue() to enable queue reconstruction and idempotency.'
            );
        }

        $payload = self::payloadWithTools($task);
        $ctx     = $task->context();

        $driverName = $this->resolveFirstConfiguredDriver($task, $drivers, $payload);

        try {
            $run = AiRun::startAsQueue($driverName, $payload, $ctx, $task);
        } catch (UniqueConstraintViolationException) {
            return AiRun::where('idempotency_key', $task->idempotencyKey())->value('id');
        }

        $job = new ProcessAiPayload(
            driverName: $driverName,
            payload: $payload,
            context: $ctx,
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
            timeout: $task->jobTimeout(),
        );

        if ($task instanceof ShouldQueueAi) {
            if ($conn = $task->preferredConnection()) {
                $job->onConnection($conn);
            }
            $job->onQueue($task->preferredQueueFor('request', config('ai-tasks.queues.default')));
        } else {
            $job->onQueue(config('ai-tasks.queues.default'));
        }

        $pending = dispatch($job);

        if ($delay !== null) {
            $pending->delay($delay);
        }

        event(new AiTaskQueued($task, $run));

        return $run->id;
    }

    public function stream(AiTask $task, callable $onChunk, array|string $drivers = []): AiResponse
    {
        $payload = self::payloadWithTools($task);
        $ctx     = $task->context();

        app(Budget::class)->ensureNotExceeded($ctx->tenantId);

        $list   = $this->resolveDrivers($task, $drivers);
        $errors = [];

        foreach ($list as $driverName) {
            if (! $this->isConfigured($driverName) && ! ($payload->providerOverride['key'] ?? null)) {
                $errors[] = "driver_not_configured: {$driverName}";
                continue;
            }

            $run = AiRun::start($driverName, $payload, $ctx, $task);

            event(new AiTaskStarted($task, $ctx, $run));

            try {
                $resp = $this->manager->driver($driverName)->stream($payload, $ctx, $onChunk);

                app(Budget::class)->ensureNotExceeded($ctx->tenantId, (float) ($resp->usage['cost'] ?? 0.0));
            } catch (BudgetExceededException $e) {
                $run->finish($resp);
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                throw $e;
            } catch (\Throwable $e) {
                $run->fail($e->getMessage());
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                $errors[] = "{$driverName}: {$e->getMessage()}";
                continue;
            }

            $run->finish($resp);

            $result = $task->postprocess($resp);
            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            self::complete($task, $result, $finalResponse, $run);

            return $finalResponse;
        }

        throw new AiDriverException('All providers failed: ' . implode(' | ', $errors));
    }

    /**
     * Calls AiTask::onCompleted() (swallowing/logging any exception it throws, so a broken
     * hook never breaks the AI pipeline itself) and fires AiTaskCompleted, in that order, at
     * every point in the package where a task's result is considered final — send()/stream()
     * here and PostprocessAiResult for the queued path.
     *
     * $result is whatever postprocess() returned (AiResponse|array) — the same value
     * isAcceptable() is consulted with — not the AiResponse-wrapped $finalResponse the event
     * carries, so a task whose postprocess() returns a plain array gets that array back in
     * onCompleted(), not an AiResponse it never asked for.
     */
    public static function complete(AiTask $task, AiResponse|array $result, AiResponse $finalResponse, AiRun $run, bool $attemptsExhausted = false): void
    {
        try {
            $task->onCompleted($result, $attemptsExhausted);
        } catch (\Throwable $e) {
            Log::error('AiTask::onCompleted() threw', [
                'task' => $task->name(),
                'run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);
            event(new AiTaskCompletedHandlerFailed($task, $e, $run));
        }

        event(new AiTaskCompleted($task, $finalResponse, $run, $attemptsExhausted));
    }

    private function resolveDrivers(AiTask $task, array|string $drivers): array
    {
        if ($drivers) {
            return is_string($drivers) ? [$drivers] : $drivers;
        }

        return $this->router->choose($task);
    }

    private function resolveFirstConfiguredDriver(AiTask $task, array|string $drivers, ?AiPayload $payload = null): string
    {
        $list = $this->resolveDrivers($task, $drivers);
        $hasCustomKey = (bool) ($payload?->providerOverride['key'] ?? null);

        foreach ($list as $name) {
            if ($this->isConfigured($name) || $hasCustomKey) {
                return $name;
            }
        }

        throw new AiDriverException("No configured driver for task [{$task->name()}]");
    }

    private function isConfigured(string $driverName): bool
    {
        if ($driverName === 'null') {
            return true;
        }

        // credentials are in laravel/ai config (config/ai.php providers section)
        $key = config("ai.providers.{$driverName}.key")
            ?? config("ai.providers.{$driverName}.access_key_id"); // bedrock

        return $key && trim((string) $key) !== '';
    }

    public static function payloadWithTools(AiTask $task): AiPayload
    {
        $payload    = $task->toPayload();
        $tools      = $task->tools();
        $schema     = $task->schema();
        $toolChoice = $task->toolChoice();

        if (empty($tools) && $schema === null && $toolChoice === null) {
            return $payload;
        }

        return new AiPayload(
            modality: $payload->modality,
            messages: $payload->messages,
            systemPrompt: $payload->systemPrompt,
            options: $payload->options,
            meta: $payload->meta,
            tools: $tools,
            jsonMode: $payload->jsonMode,
            providerOverride: $payload->providerOverride,
            schema: $schema,
            toolChoice: $toolChoice,
            decisions: $payload->decisions,
        );
    }

    private function runPostprocess(AiResponse $resp): AiResponse
    {
        if (! config('ai-tasks.postprocess.enabled', false)) {
            return $resp;
        }

        return app(Pipeline::class)
            ->send($resp)
            ->through(config('ai-tasks.postprocess.pipes', []))
            ->thenReturn();
    }
}
