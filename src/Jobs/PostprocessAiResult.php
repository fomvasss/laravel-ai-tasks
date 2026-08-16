<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
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

        if ($run->status !== 'ok') {
            return;
        }

        $resp = new AiResponse(
            ok: true,
            content: $run->response['content'] ?? null,
            toolCalls: $run->response['tool_calls'] ?? [],
            structured: $run->response['structured'] ?? null,
            finishReason: $run->response['finish_reason'] ?? null,
        );

        /** @var class-string<AiTask> $cls */
        $cls  = $this->taskClass;
        $task = $cls::fromQueueArgs($this->taskCtorArgs);

        $resp = $this->runPostprocess($resp);

        $result = $task->postprocess($resp);

        $accepted = $task->isAcceptable($result);

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
