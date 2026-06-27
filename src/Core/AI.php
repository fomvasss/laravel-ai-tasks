<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Events\AiTaskFailed;
use Fomvasss\AiTasks\Events\AiTaskQueued;
use Fomvasss\AiTasks\Events\AiTaskStarted;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pipeline\Pipeline;

class AI
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly Router    $router,
    ) {}

    public function send(AiTask $task, array|string $drivers = []): AiResponse
    {
        $payload = $this->payloadWithTools($task);
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

            $resp = $this->manager->driver($driverName)->send($payload, $ctx);

            if (! $resp->ok) {
                $run->fail($resp->error ?? 'unknown_error');
                event(new AiTaskFailed($task, $resp->error ?? 'unknown_error', $run));
                $errors[] = "{$driverName}: {$resp->error}";
                continue;
            }

            app(Budget::class)->ensureNotExceeded($ctx->tenantId, (float) ($resp->usage['cost'] ?? 0.0));

            $run->finish($resp);

            $resp = $this->runPostprocess($resp);
            $result = $task->postprocess($resp);

            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            event(new AiTaskCompleted($task, $finalResponse, $run));

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

        $payload = $this->payloadWithTools($task);
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
        $payload = $this->payloadWithTools($task);
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

            $resp = $this->manager->driver($driverName)->stream($payload, $ctx, $onChunk);

            $run->finish($resp);

            $result = $task->postprocess($resp);
            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            event(new AiTaskCompleted($task, $finalResponse, $run));

            return $finalResponse;
        }

        throw new AiDriverException('All providers failed: ' . implode(' | ', $errors));
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

    private function payloadWithTools(AiTask $task): AiPayload
    {
        $payload = $task->toPayload();
        $tools   = $task->tools();

        if (empty($tools)) {
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
