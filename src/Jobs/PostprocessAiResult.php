<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\ExecutionContext;
use Fomvasss\AiTasks\Support\QueueDispatch;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostprocessAiResult implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $aiRunId,
        public readonly string  $taskClass,
        public readonly array   $taskCtorArgs = [],
        public readonly int     $attempt = 0,
    ) {}

    public function handle(): void
    {
        $run = AiRun::findOrFail($this->aiRunId);

        // 'paused' — the call stopped before a tool that needs approval: postprocess() and
        // onCompleted() still run, it's the moment the app asks the user
        if (! in_array($run->status, ['ok', 'paused'], true)) {
            return;
        }

        // postprocess()/onCompleted(), and a retry's tools(), see the user and locale of the dispatch
        ExecutionContext::run($this->taskClass, $run->executionContext(), fn () => $this->process($run));
    }

    private function process(AiRun $run): void
    {

        $resp = new AiResponse(
            ok: true,
            content: $run->response['content'] ?? null,
            toolCalls: $run->response['tool_calls'] ?? [],
            structured: $run->response['structured'] ?? null,
            finishReason: $run->response['finish_reason'] ?? null,
            pendingApprovals: $run->response['pending_approvals'] ?? [],
            runId: $run->id,
            // the provider's usage isn't kept as is — rebuilt from the run's columns, the same
            // keys AI::send() returns (modality-specific extras like audio_seconds are not stored)
            usage: array_filter([
                'driver' => $run->driver,
                'model' => $run->model,
                'tokens_in' => $run->tokens_in,
                'tokens_out' => $run->tokens_out,
                'cache_read_tokens' => $run->cache_read_tokens,
                'cache_write_tokens' => $run->cache_write_tokens,
                'cost' => $run->cost,
                'cost_rates' => $run->cost_rates,
            ], fn (mixed $v): bool => $v !== null),
        );

        /** @var class-string<AiTask> $cls */
        $cls  = $this->taskClass;
        $task = $cls::fromQueueArgs($this->taskCtorArgs);

        $resp = $this->runPostprocess($resp);

        $result = $task->postprocess($resp);

        // A pause is a complete answer for now — re-running it would only ask the user again
        $accepted = $resp->paused() || $task->isAcceptable($result);

        if (! $accepted && $this->attempt < $task->maxRetries()) {
            $this->retry($task, $run);
            return;
        }

        $finalResponse = $result instanceof AiResponse
            ? $result
            : new AiResponse(true, json_encode($result));

        AI::complete($task, $result, $finalResponse, $run, attemptsExhausted: ! $accepted);
    }

    /**
     * Dispatch a fresh ProcessAiPayload/PostprocessAiResult pair for the same task, on the same
     * driver as the original run. The task's own idempotencyKey()/serializeForQueue() are untouched —
     * the retry-generation suffix is derived here, not by the task.
     */
    private function retry(AiTask $task, AiRun $run): void
    {
        $nextAttempt = $this->attempt + 1;

        $payload = AI::payloadWithTools($task);
        $ctx     = $task->context();

        try {
            $newRun = AiRun::startAsQueue(
                $run->driver,
                $payload,
                $ctx,
                $task,
                // fall back to the original run id so tasks without an idempotency key
                // don't all collide on the same '-retryN' value
                idempotencyKey: ($task->idempotencyKey() ?? $run->id) . '-retry' . $nextAttempt,
                executionContext: $run->executionContext(),
            );
        } catch (UniqueConstraintViolationException) {
            // this retry generation was already dispatched (e.g. re-processed job) — nothing to do
            return;
        }

        Log::info('AiTask result rejected by isAcceptable(), retrying', [
            'task' => $task->name(),
            'attempt' => $nextAttempt,
            'run_id' => $newRun->id,
            'retried_run_id' => $run->id,
            'driver' => $run->driver,
        ]);

        $job = new ProcessAiPayload(
            driverName: $run->driver,
            payload: $payload,
            context: $ctx,
            runId: $newRun->id,
            taskClass: $this->taskClass,
            taskCtorArgs: $this->taskCtorArgs,
            timeout: $task->jobTimeout(),
            attempt: $nextAttempt,
        );

        QueueDispatch::configure($job, $task, 'request', config('ai-tasks.queues.default'));

        dispatch($job);
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
