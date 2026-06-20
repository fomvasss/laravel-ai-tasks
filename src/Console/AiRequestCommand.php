<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Support\TenantResolver;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AiRequestCommand extends Command
{
    protected $signature = 'ai:request
        {prompt? : Prompt text}
        {--driver=    : Driver name (openai|anthropic|gemini|...)}
        {--modality=text : text|embed}
        {--temperature=0.3}
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
        $temp     = (float) $this->option('temperature');
        $tenant   = $this->option('tenant');

        $task = new class('adhoc', $modality, $prompt, $temp, $tenant) extends AiTask {
            public function __construct(
                private readonly string  $taskName,
                private readonly string  $modalityVal,
                private readonly string  $prompt,
                private readonly float   $temperature,
                private readonly ?string $tenantOverride,
            ) {}

            public function name(): string     { return $this->taskName; }
            public function modality(): string { return $this->modalityVal; }
            public function idempotencyKey(): string { return hash('xxh3', $this->prompt); }

            public function context(): AiContext
            {
                return new AiContext(
                    tenantId: $this->tenantOverride ?? app(TenantResolver::class)->id(),
                    taskName: $this->name(),
                );
            }

            public function toPayload(): AiPayload
            {
                return new AiPayload(
                    modality: $this->modality(),
                    messages: [new UserMessage($this->prompt)],
                    options: ['temperature' => $this->temperature],
                );
            }
        };

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
