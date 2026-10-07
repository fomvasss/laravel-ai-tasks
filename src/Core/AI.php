<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Events\AiTaskCompletedHandlerFailed;
use Fomvasss\AiTasks\Events\AiTaskFailed;
use Fomvasss\AiTasks\Events\AiTaskFailedFinally;
use Fomvasss\AiTasks\Events\AiTaskQueued;
use Fomvasss\AiTasks\Events\AiTaskStarted;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Exceptions\ApprovalResumeException;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\ApprovalDecisions;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Support\DriverHealth;
use Fomvasss\AiTasks\Support\ExecutionContext;
use Fomvasss\AiTasks\Support\Failover;
use Fomvasss\AiTasks\Support\ModelLister;
use Fomvasss\AiTasks\Support\PausedTurn;
use Fomvasss\AiTasks\Support\QueueDispatch;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Tasks\PromptTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

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
        // the whole call under the task's context — tools(), toPayload() and the tenant/user of
        // a task that runs on someone's behalf from a job with nobody logged in included
        $execution = $task->executionContext();

        return $task::withExecutionContext($execution, fn (): AiResponse => $this->sendPayload($task, self::payloadWithTools($task), $drivers, $execution));
    }

    private function sendPayload(AiTask $task, AiPayload $payload, array|string $drivers, ?array $execution = null): AiResponse
    {
        $ctx       = $task->context();
        $execution ??= $task->executionContext();

        try {
            app(Budget::class)->ensureNotExceeded($ctx->tenantId);
        } catch (BudgetExceededException $e) {
            throw self::failFinally($task, $e);
        }

        $list   = $this->resolveDrivers($task, $drivers);
        $errors = [];
        $run    = null;
        $failed = [];

        foreach ($list as $driverName) {
            $run = AiRun::start($driverName, $payload, $ctx, $task, $execution);

            if (! $this->isConfigured($driverName) && ! ($payload->providerOverride['key'] ?? null)) {
                $run->skip("driver_not_configured: {$driverName}");
                $errors[] = "driver_not_configured: {$driverName}";
                continue;
            }

            event(new AiTaskStarted($task, $ctx, $run));

            try {
                $resp = $this->manager->driver($driverName)->send($payload, $ctx);

                if ($resp->ok) {
                    DriverHealth::recordSuccess($driverName, $payload);
                }

                if (! $resp->ok) {
                    $run->fail($resp->error ?? 'unknown_error');
                    event(new AiTaskFailed($task, $resp->error ?? 'unknown_error', $run));
                    $errors[] = "{$driverName}: {$resp->error}";
                    $failed[] = $run;
                    continue;
                }

                app(Budget::class)->ensureNotExceeded($ctx->tenantId, (float) ($resp->usage['cost'] ?? 0.0));
            } catch (BudgetExceededException $e) {
                // Виклик провайдера вже відбувся й оплачений — статус чесний ('error'), але
                // cost зберігається через fail($usage), щоб витрата не випала з майбутніх
                // підрахунків бюджету (issue #7): Budget сумує по cost, не по status.
                $run->fail($e->getMessage(), $resp->usage);
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                throw self::failFinally($task, $e, $run);
            } catch (\Throwable $e) {
                $run->fail($e->getMessage());
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                DriverHealth::recordFailure($driverName, $payload, $e);
                $errors[] = "{$driverName}: {$e->getMessage()}";

                if (! Failover::shouldTryNext($e)) {
                    throw self::failFinally($task, new AiDriverException('Provider rejected the request: ' . implode(' | ', $errors), 0, $e), $run);
                }

                $failed[] = $run;

                continue;
            }

            $run->finish($resp);
            $resp = $resp->withRunId($run->id);

            foreach ($failed as $attempt) {
                $attempt->supersede($run->id);
            }

            $resp = $this->runPostprocess($resp);
            $result = $task->postprocess($resp);

            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            // a postprocess() returning an array or a fresh AiResponse still gets the run id
            $finalResponse = $finalResponse->runId !== null ? $finalResponse : $finalResponse->withRunId($run->id);

            self::complete($task, $result, $finalResponse, $run);

            return $finalResponse;
        }

        throw self::failFinally($task, new AiDriverException('All providers failed: ' . implode(' | ', $errors)), $run);
    }

    public function queue(AiTask $task, array|string $drivers = [], \DateTimeInterface|\DateInterval|int|null $delay = null): string
    {
        self::ensureQueueable($task);

        $execution = $task->executionContext();

        return $task::withExecutionContext($execution, fn (): string => $this->queuePayload($task, self::payloadWithTools($task), $drivers, $delay, $execution));
    }

    private static function ensureQueueable(AiTask $task): void
    {
        $ctor = (new \ReflectionClass($task))->getConstructor();
        if ($ctor && $ctor->getNumberOfRequiredParameters() > 0 && empty($task->serializeForQueue())) {
            throw new \LogicException(
                $task::class . ' has constructor parameters but serializeForQueue() returns []. ' .
                'Implement serializeForQueue() to enable queue reconstruction and idempotency.'
            );
        }
    }

    private function queuePayload(AiTask $task, AiPayload $payload, array|string $drivers, \DateTimeInterface|\DateInterval|int|null $delay = null, ?array $execution = null): string
    {
        $ctx       = $task->context();
        $execution ??= $task->executionContext();

        $chain      = $this->resolveConfiguredChain($task, $drivers, $payload);
        $driverName = $chain[0];

        try {
            $run = AiRun::startAsQueue(
                $driverName, $payload, $ctx, $task,
                // the resumed run shares the task's args, and so its idempotency key, with the paused one
                idempotencyKey: $task->resumingRun() ? 'resume:' . $task->resumingRun()->id : null,
                executionContext: $execution,
            );
        } catch (UniqueConstraintViolationException) {
            return AiRun::where('idempotency_key', $task->idempotencyKey())->value('id');
        }

        $dispatchId = $run->newDispatch(match (true) {
            $delay === null => null,
            $delay instanceof \DateTimeInterface => $delay,
            $delay instanceof \DateInterval => now()->add($delay),
            default => now()->addSeconds($delay),
        });

        $job = new ProcessAiPayload(
            driverName: $driverName,
            payload: $payload,
            context: $ctx,
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
            timeout: $task->jobTimeout(),
            fallbackDrivers: array_slice($chain, 1),
            dispatchId: $dispatchId,
        );

        QueueDispatch::configure($job, $task, 'request', config('ai-tasks.queues.default'));

        // A resume runs the approved tool before the provider call — a retried job would run it again
        if ($task->resumingRun() !== null) {
            $job->tries = 1;
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
        $execution = $task->executionContext();

        return $task::withExecutionContext($execution, fn (): AiResponse => $this->streamUnderContext($task, $onChunk, $drivers, $execution));
    }

    private function streamUnderContext(AiTask $task, callable $onChunk, array|string $drivers, array $execution): AiResponse
    {
        $payload = self::payloadWithTools($task);
        $ctx     = $task->context();

        try {
            app(Budget::class)->ensureNotExceeded($ctx->tenantId);
        } catch (BudgetExceededException $e) {
            throw self::failFinally($task, $e);
        }

        $list   = $this->resolveDrivers($task, $drivers);
        $errors = [];
        $run    = null;
        $failed = [];

        foreach ($list as $driverName) {
            $run = AiRun::start($driverName, $payload, $ctx, $task, $execution);

            if (! $this->isConfigured($driverName) && ! ($payload->providerOverride['key'] ?? null)) {
                $run->skip("driver_not_configured: {$driverName}");
                $errors[] = "driver_not_configured: {$driverName}";
                continue;
            }

            event(new AiTaskStarted($task, $ctx, $run));

            $streamed = false;

            try {
                $resp = $this->manager->driver($driverName)->stream($payload, $ctx, function (string $delta) use ($onChunk, &$streamed): void {
                    $streamed = true;
                    $onChunk($delta);
                });

                DriverHealth::recordSuccess($driverName, $payload);

                app(Budget::class)->ensureNotExceeded($ctx->tenantId, (float) ($resp->usage['cost'] ?? 0.0));
            } catch (BudgetExceededException $e) {
                // див. коментар в send() — статус 'error', cost зберігається для бюджету
                $run->fail($e->getMessage(), $resp->usage);
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                throw self::failFinally($task, $e, $run);
            } catch (\Throwable $e) {
                $run->fail($e->getMessage());
                event(new AiTaskFailed($task, $e->getMessage(), $run));
                DriverHealth::recordFailure($driverName, $payload, $e);
                $errors[] = "{$driverName}: {$e->getMessage()}";

                if (! Failover::shouldTryNext($e)) {
                    throw self::failFinally($task, new AiDriverException('Provider rejected the request: ' . implode(' | ', $errors), 0, $e), $run);
                }

                // Частину відповіді клієнт уже отримав — наступний драйвер почав би її з нуля.
                if ($streamed) {
                    throw self::failFinally($task, new AiDriverException('Stream failed after output started: ' . implode(' | ', $errors), 0, $e), $run);
                }

                $failed[] = $run;

                continue;
            }

            $run->finish($resp);
            $resp = $resp->withRunId($run->id);

            foreach ($failed as $attempt) {
                $attempt->supersede($run->id);
            }

            $resp = $this->runPostprocess($resp);
            $result = $task->postprocess($resp);
            $finalResponse = $result instanceof AiResponse
                ? $result
                : new AiResponse(true, json_encode($result));

            // a postprocess() returning an array or a fresh AiResponse still gets the run id
            $finalResponse = $finalResponse->runId !== null ? $finalResponse : $finalResponse->withRunId($run->id);

            self::complete($task, $result, $finalResponse, $run);

            return $finalResponse;
        }

        throw self::failFinally($task, new AiDriverException('All providers failed: ' . implode(' | ', $errors)), $run);
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

    /**
     * The failure counterpart of complete(): calls AiTask::onFailed() (a throwing hook is
     * logged, never breaks the pipeline) and fires AiTaskFailedFinally — at every point where
     * a task ends without a result. Returns the reason so a sync caller can `throw` it inline.
     *
     * @template T of \Throwable|string
     * @param T $reason
     * @return T
     */
    public static function failFinally(AiTask $task, \Throwable|string $reason, ?AiRun $run = null): \Throwable|string
    {
        try {
            $task->onFailed($reason);
        } catch (\Throwable $e) {
            Log::error('AiTask::onFailed() threw', [
                'task' => $task->name(),
                'run_id' => $run?->id,
                'exception' => $e->getMessage(),
            ]);
        }

        event(new AiTaskFailedFinally($task, $reason, $run));

        return $reason;
    }

    private function resolveDrivers(AiTask $task, array|string $drivers): array
    {
        if ($drivers) {
            return is_string($drivers) ? [$drivers] : $drivers;
        }

        return $this->router->choose($task);
    }

    /**
     * Драйвери ланцюжка, які черговий job може пробувати по черзі. Зі своїм ключем у payload
     * (providerOverride) ланцюжок — лише перший драйвер: override підміняє провайдера для
     * будь-якого драйвера, тож "запасний" пішов би в той самий збійний API тим самим ключем.
     *
     * @return non-empty-list<string>
     */
    private function resolveConfiguredChain(AiTask $task, array|string $drivers, ?AiPayload $payload = null): array
    {
        $list = $this->resolveDrivers($task, $drivers);

        if ($payload?->providerOverride['key'] ?? null) {
            if ($list) {
                return [$list[0]];
            }
        } elseif ($chain = array_values(array_filter($list, fn (string $name): bool => $this->isConfigured($name)))) {
            return $chain;
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
        $paused     = $task->resumingRun();

        if (empty($tools) && $schema === null && $toolChoice === null && $paused === null) {
            return $payload;
        }

        return new AiPayload(
            modality: $payload->modality,
            // a resume replays the paused turn after the history toPayload() rebuilt as of the pause
            messages: $paused
                ? [...$payload->messages, ...PausedTurn::restore($paused->response['resume']['messages'] ?? [])]
                : $payload->messages,
            systemPrompt: $payload->systemPrompt,
            options: $payload->options,
            meta: $paused ? [...$payload->meta, 'resumed_from' => $paused->id] : $payload->meta,
            tools: $tools,
            jsonMode: $payload->jsonMode,
            providerOverride: $payload->providerOverride,
            schema: $schema,
            // a forced tool choice applied again on the continuation would force another tool call
            toolChoice: $paused ? null : $toolChoice,
            decisions: $paused ? $task->resumeDecisions() : $payload->decisions,
        );
    }

    /**
     * Continues a run paused for tool approval (AiResponse::paused()) with the user's decisions,
     * synchronously — a new run, linked by request.meta.resumed_from. $task is a fresh instance
     * of the task that paused; its toPayload() sees resumingRun() and returns the history as of
     * the pause. The pause is claimed atomically: a second resume of the same run throws.
     *
     * @param Decisions|array<string, Decision|bool> $decisions ['tool_call_id' => true|false|Decision]
     */
    public function resume(AiTask $task, string $runId, Decisions|array $decisions, array|string $drivers = []): AiResponse
    {
        return $this->resuming($task, $runId, $decisions, fn (AiPayload $payload, string $driver): AiResponse => $this->sendPayload($task, $payload, $driver), $drivers);
    }

    /**
     * Closes a pause the app won't continue (the user declined, wrote something else), so the run
     * stops showing as open. False when it was not paused any more. A rejection that should reach
     * the model goes through resume() with a `false` decision instead.
     */
    public function dismissPause(string $runId): bool
    {
        return AiRun::find($runId)?->dismissPause() ?? false;
    }

    /** resume() through the queue; returns the new run id. */
    public function queueResume(AiTask $task, string $runId, Decisions|array $decisions, array|string $drivers = []): string
    {
        self::ensureQueueable($task);

        return $this->resuming($task, $runId, $decisions, fn (AiPayload $payload, string $driver): string => $this->queuePayload($task, $payload, $driver), $drivers);
    }

    /**
     * @template T
     * @param \Closure(AiPayload, string $driver): T $dispatch
     * @return T
     */
    private function resuming(AiTask $task, string $runId, Decisions|array $decisions, \Closure $dispatch, array|string $drivers): mixed
    {
        $paused = AiRun::findOrFail($runId);

        if (! $paused->isPaused()) {
            throw match (true) {
                isset($paused->response['resume']['resumed_at']) => ApprovalResumeException::alreadyResolved($runId),
                isset($paused->response['resume']['expired_at']) => ApprovalResumeException::expired($runId),
                isset($paused->response['resume']['dismissed_at']) => ApprovalResumeException::notPaused($runId, 'dismissed'),
                default => ApprovalResumeException::notPaused($runId, $paused->status),
            };
        }

        if (($paused->request['task_class'] ?? null) !== $task::class) {
            throw ApprovalResumeException::otherTask($runId, (string) ($paused->request['task_class'] ?? ''), $task::class);
        }

        if ($paused->pauseExpired()) {
            $paused->expirePause();

            throw ApprovalResumeException::expired($runId);
        }

        $decisions = $decisions instanceof Decisions ? $decisions : Decisions::from($decisions);

        // Under the paused run's context: tools() and the provider call act as the user who
        // started it — not whoever answers (a webhook, a manager on their behalf)
        // The resume state lives on the task instance only for this call — a refused resume
        // leaves the instance an ordinary task, so the app can send()/queue() it as a normal turn
        try {
            return $this->resumeUnderContext($task, $paused, $decisions, $dispatch, $drivers);
        } finally {
            $task->endResume();
        }
    }

    private function resumeUnderContext(AiTask $task, AiRun $paused, Decisions $decisions, \Closure $dispatch, array|string $drivers): mixed
    {
        return ExecutionContext::run($task::class, $paused->executionContext(), function () use ($task, $paused, $decisions, $dispatch, $drivers): mixed {
            $task->beginResume($paused, $decisions);

            // built once: tools() may have side effects, and the check needs the same tools the call gets
            $payload = self::payloadWithTools($task);

            $missing = array_values(array_diff(
                array_column($paused->response['pending_approvals'] ?? [], 'tool'),
                ApprovalDecisions::toolNames($payload->tools),
            ));

            if ($missing !== []) {
                throw ApprovalResumeException::toolsMissing($paused->id, $missing);
            }

            if (! $paused->claimPause()) {
                throw ApprovalResumeException::alreadyResolved($paused->id);
            }

            // One driver, no fallback: the approved tool runs at the start of the continuation, so
            // a second driver after a failure would run it again. The paused run's driver by default —
            // another provider may reject the replayed turn (Gemini does OpenAI's).
            return $dispatch($payload, ((array) $drivers)[0] ?? $paused->driver);
        });
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
