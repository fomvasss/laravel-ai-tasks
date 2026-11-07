<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Console\Command;

class AiPruneCommand extends Command
{
    protected $signature = 'ai:prune
        {--chunk=500 : Rows per batch}
        {--dry-run : Only count what would be pruned}';

    protected $description = 'Apply ai-tasks.retention: delete old ai_runs, strip the prompt and output from the rest';

    public function handle(): int
    {
        $days = AiRun::retentionDays('days');
        $payloadDays = AiRun::retentionDays('payload_days');

        if ($days === null && $payloadDays === null) {
            $this->info('Retention is off: set ai-tasks.retention.days and/or payload_days.');
            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));

        if ($this->option('dry-run')) {
            $this->line('Rows to delete: ' . ($days === null ? 'off' : (new AiRun)->prunable()->count()));
            $this->line('Payloads to strip: ' . ($payloadDays === null ? 'off' : AiRun::query()->payloadExpired()->count()));
            return self::SUCCESS;
        }

        // Deleted first — rows going away entirely don't need stripping
        $deleted = $days === null ? 0 : (new AiRun)->pruneAll($chunk);

        $stripped = 0;

        if ($payloadDays !== null) {
            AiRun::query()
                ->payloadExpired()
                ->select(['id', 'status', 'request', 'response'])
                ->chunkById($chunk, function ($runs) use (&$stripped): void {
                    foreach ($runs as $run) {
                        $stripped += (int) $run->prunePayload();
                    }
                });
        }

        $this->info("Deleted {$deleted} run(s), stripped the payload of {$stripped}.");

        return self::SUCCESS;
    }
}
