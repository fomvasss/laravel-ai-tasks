<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Tasks\AiTask;

/**
 * Applies a task's preferred connection/queue (via ShouldQueueAi) to a job about to be
 * dispatched — shared by AI::queue(), ProcessAiPayload (post stage), PostprocessAiResult
 * (retry) and ai:retry, which all need the same request/post routing.
 *
 * @param object{onQueue: callable, onConnection: callable} $job a job using Illuminate\Bus\Queueable
 */
final class QueueDispatch
{
    public static function configure(object $job, AiTask $task, string $stage, string $fallback): void
    {
        if (! $task instanceof ShouldQueueAi) {
            $job->onQueue($fallback);
            return;
        }

        if ($conn = $task->preferredConnection()) {
            $job->onConnection($conn);
        }

        $job->onQueue($task->preferredQueueFor($stage, $fallback));
    }
}
