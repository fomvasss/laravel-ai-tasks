<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Console\Command;

class AiRetryFailed extends Command
{
    protected $signature = 'ai:retry {--since=24h : Period, e.g. 24h, 1h}';

    protected $description = 'List failed ai_runs for retry';

    public function handle(): int
    {
        $hours = (int) rtrim((string) $this->option('since'), 'h');
        $since = now()->subHours($hours);

        $runs = AiRun::where('status', 'error')
            ->where('created_at', '>=', $since)
            ->limit(50)
            ->get(['id', 'task', 'driver', 'error', 'created_at']);

        if ($runs->isEmpty()) {
            $this->info('No failed runs found.');
            return self::SUCCESS;
        }

        $this->table(['ID', 'Task', 'Driver', 'Error', 'At'], $runs->toArray());

        return self::SUCCESS;
    }
}
