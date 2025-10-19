<?php

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Console\Command;

class AiRunsList extends Command
{
    protected $signature = 'ai:runs {--tenant=} {--task=} {--status=} {--limit=20}';
    
    protected $description = 'Show latest ai_runs';

    public function handle(): int
    {
        $q = AiRun::query()->latest();
        if ($v = $this->option('tenant')) $q->where('tenant_id', $v);
        if ($v = $this->option('task')) $q->where('task', $v);
        if ($v = $this->option('status')) $q->where('status', $v);

        $rows = $q->limit((int)$this->option('limit'))->get(['id','tenant_id','task','driver','status','duration_ms','created_at']);
        $this->table(['ID','Tenant','Task','Driver','Status','ms','At'], $rows->toArray());

        return self::SUCCESS;
    }
}
