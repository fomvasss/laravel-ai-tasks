<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\QueueArgs;
use Fomvasss\AiTasks\Support\QueueDispatch;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Console\Command;

class AiRetryFailed extends Command
{
    protected $signature = 'ai:retry
        {--since=24h : Period, e.g. 24h, 1h}
        {--limit=50}
        {--dry-run : Only list runs that would be retried}';

    protected $description = 'Re-dispatch failed ai_runs (status error/dead) by reconstructing their tasks';

    public function handle(): int
    {
        $hours = (int) rtrim((string) $this->option('since'), 'h');
        $since = now()->subHours($hours);

        $runs = AiRun::whereIn('status', ['error', 'dead'])
            ->where('created_at', '>=', $since)
            ->limit((int) $this->option('limit'))
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No failed runs found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            $task = $this->reconstructTask($run);

            if ($task === null) {
                $rows[] = [$run->id, $run->task, $run->driver, 'skipped: task not reconstructable (no task_args stored — enable AI_STORE_REQUEST)'];
                continue;
            }

            if ($this->option('dry-run')) {
                $rows[] = [$run->id, $run->task, $run->driver, 'would retry'];
                continue;
            }

            $this->redispatch($run, $task);
            $rows[] = [$run->id, $run->task, $run->driver, 'queued'];
        }

        $this->table(['ID', 'Task', 'Driver', 'Result'], $rows);

        return self::SUCCESS;
    }

    private function reconstructTask(AiRun $run): ?AiTask
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

    private function redispatch(AiRun $run, AiTask $task): void
    {
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
    }
}
