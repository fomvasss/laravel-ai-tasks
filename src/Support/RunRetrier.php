<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;

/**
 * Re-dispatches a stored run by rebuilding its task from ai_runs.request.
 *
 * Shared by the `ai:retry` command and the dashboard's Retry button — a second copy of this
 * would drift the moment one of the two callers gains a fix.
 */
final class RunRetrier
{
    /** Null when the run predates (or was recorded without) store_request — no ctor args to revive. */
    public static function reconstruct(AiRun $run): ?AiTask
    {
        $class = $run->request['task_class'] ?? null;
        $args  = $run->request['task_args'] ?? null;

        if (! $class || ! class_exists($class) || ! is_subclass_of($class, AiTask::class)) {
            return null;
        }

        $required = (new \ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0;

        if ($args === null && $required > 0) {
            return null;
        }

        return $class::fromQueueArgs(QueueArgs::revive($args ?? []));
    }

    /** False when the run is not reconstructable; the row is left untouched in that case. */
    public static function retry(AiRun $run): bool
    {
        $task = self::reconstruct($run);

        if ($task === null) {
            return false;
        }

        // The same row is reused rather than a new one created, so its unique idempotency_key
        // cannot collide with itself.
        $run->update([
            'status' => 'queued',
            'error' => null,
            'finished_at' => null,
            'duration_ms' => null,
        ]);

        $job = new ProcessAiPayload(
            driverName: $run->driver,
            payload: AI::payloadWithTools($task),
            context: $task->context(),
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
            timeout: $task->jobTimeout(),
        );

        QueueDispatch::configure($job, $task, 'request', config('ai-tasks.queues.default'));

        dispatch($job);

        return true;
    }
}
