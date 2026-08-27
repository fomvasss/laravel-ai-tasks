<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\RunRetrier;
use Illuminate\Console\Command;

class AiRetryFailed extends Command
{
    protected $signature = 'ai:retry
        {--since=24h : Period, e.g. 24h, 1h}
        {--limit=50}
        {--stuck : Also pick up runs still queued/running with no progress (a lost queue payload)}
        {--dry-run : Only list runs that would be retried}';

    protected $description = 'Re-dispatch failed ai_runs (status error/dead) by reconstructing their tasks';

    public function handle(): int
    {
        $hours = (int) rtrim((string) $this->option('since'), 'h');
        $since = now()->subHours($hours);

        $runs = AiRun::query()
            ->where(function ($q) {
                $q->whereIn('status', ['error', 'dead']);

                // A dropped queue payload leaves the row 'queued' forever — nothing ever fails it,
                // so without this the only case that truly cannot self-heal is also the one the
                // command cannot reach.
                if ($this->option('stuck')) {
                    $q->orWhere(fn ($sq) => $sq->stuck());
                }
            })
            ->where('created_at', '>=', $since)
            ->limit((int) $this->option('limit'))
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No failed runs found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            if (RunRetrier::reconstruct($run) === null) {
                $rows[] = [$run->id, $run->task, $run->driver, 'skipped: task not reconstructable (no task_args stored — enable AI_STORE_REQUEST)'];
                continue;
            }

            if ($this->option('dry-run')) {
                $rows[] = [$run->id, $run->task, $run->driver, 'would retry'];
                continue;
            }

            RunRetrier::retry($run);
            $rows[] = [$run->id, $run->task, $run->driver, 'queued'];
        }

        $this->table(['ID', 'Task', 'Driver', 'Result'], $rows);

        return self::SUCCESS;
    }
}
