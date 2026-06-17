<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Console\Command;

class AiRunsList extends Command
{
    protected $signature = 'ai:runs
        {--tenant= : Filter by tenant_id}
        {--task=   : Filter by task name}
        {--status= : Filter by status}
        {--limit=20}';

    protected $description = 'Show latest ai_runs';

    public function handle(): int
    {
        $q = AiRun::query()->latest();

        if ($v = $this->option('tenant')) {
            $q->where('tenant_id', $v);
        }
        if ($v = $this->option('task')) {
            $q->where('task', $v);
        }
        if ($v = $this->option('status')) {
            $q->where('status', $v);
        }

        $rows = $q->limit((int) $this->option('limit'))
            ->get(['id', 'tenant_id', 'task', 'driver', 'status', 'tokens_in', 'tokens_out', 'cost', 'duration_ms', 'created_at'])
            ->toArray();

        $this->table(
            ['ID', 'Tenant', 'Task', 'Driver', 'Status', 'In', 'Out', 'Cost', 'ms', 'At'],
            $rows
        );

        return self::SUCCESS;
    }
}
