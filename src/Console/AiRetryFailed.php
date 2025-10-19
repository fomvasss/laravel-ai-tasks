<?php

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Console\Command;

class AiRetryFailed extends Command
{
    protected $signature = 'ai:retry {--since=24h}';
    
    protected $description = 'Put unsuccessful calls from ai_runs on hold';

    public function handle(): int
    {
        $since = now()->subSeconds((int) rtrim($this->option('since'),'h'));
        $runs = AiRun::where('status','error')->where('created_at','>=',$since)->limit(50)->get();
        foreach ($runs as $run) {
            $this->line("Would retry run {$run->id}");
        }

        return self::SUCCESS;
    }
}
