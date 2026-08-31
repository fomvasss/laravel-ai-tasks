<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Tasks\AdhocRequestTask;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AiRequestCommand extends Command
{
    protected $signature = 'ai:request
        {prompt? : Prompt text}
        {--driver=    : Driver name (openai|anthropic|gemini|...)}
        {--modality=text : text|embed}
        {--temperature= : Sampling temperature; omitted by default (some models reject it)}
        {--tenant=    : Tenant ID override}
        {--queue      : Dispatch to queue instead of sync}
        {--json       : Output content only}
        {--raw        : Output usage info}';

    protected $description = 'Ad-hoc request to AI';

    public function handle(AI $ai): int
    {
        $prompt = (string) ($this->argument('prompt') ?? '');

        if ($prompt === '') {
            $this->error('Provide a prompt.');
            return self::FAILURE;
        }

        $driver   = $this->option('driver');
        $modality = (string) ($this->option('modality') ?: 'text');
        $temp     = ($t = $this->option('temperature')) !== null ? (float) $t : null;
        $tenant   = $this->option('tenant');

        $task = new AdhocRequestTask($prompt, $modality, $temp, $tenant);

        $drivers = $driver ? [$driver] : [];

        if ($this->option('queue')) {
            $runId = $ai->queue($task, $drivers);
            $this->info("Queued. ai_runs.id={$runId}");
            return self::SUCCESS;
        }

        try {
            $resp = $ai->send($task, $drivers);

            if ($this->option('json')) {
                $this->line((string) ($resp->content ?? ''));
                return self::SUCCESS;
            }

            $this->info('OK');
            $this->line(Str::limit($resp->content ?? '', 10_000));

            if ($this->option('raw')) {
                $this->line(json_encode($resp->usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
