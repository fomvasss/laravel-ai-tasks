<?php

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AiRequestCommand extends Command
{
    protected $signature = 'ai:request
        {prompt? : Prompt (text)}
        {--messages= : JSON array messages[] with role/content (OpenAI format)}
        {--driver= : Whith driver (openai|gemini|...)}        
        {--modality=text : text|chat|image|vision|embed}
        {--temperature=0.3 : Temperature}
        {--locale=uk : Locale}
        {--route : Route name from ai.routing config}
        {--tenant= : TenantId}
        {--queue : Send to queue instead of sync}
        {--json : Get only JSON-response (content)}
        {--raw : Get raw/usage array output}';

    protected $description = 'Ad-hoc requet to AI';

    public function handle(AI $ai): int
    {
        $messages = $this->buildMessages();
        if (!$messages) {
            $this->error('Немає промпта. Використай аргумент {prompt} або --file/--messages.');
            return self::FAILURE;
        }

        $route     = (string) $this->option('route') ?: 'adhoc.request';
        $driver    = $this->option('driver');
        $modality  = (string) $this->option('modality') ?: 'text';
        $temp      = (float)  $this->option('temperature');
        $locale    = (string) $this->option('locale') ?: 'uk';
        $tenantOpt = $this->option('tenant');

        if ($driver) {
            $current = config("ai.routing.$route");
            config()->set("ai.routing.$route", [$driver]);
            $this->line("Routing override: {$route} → [{$driver}]");
            // (нічого не публікуємо, це лише на час виконання команди)
        }

        // Temporary AiTask class
        $task = new class($route, $modality, $messages, $temp, $locale, $tenantOpt) extends AiTask {
            public function __construct(
                private string $route,
                private string $modalityVal,
                private array  $messages,
                private float  $temperature,
                private string $locale,
                private ?string $tenantOpt,
            ) {}

            public function name(): string { return $this->route; }
            public function modality(): string { return $this->modalityVal; }

            public function context(): \Fomvasss\AiTasks\DTO\AiContext
            {
                $tenant = $this->tenantOpt ?: app(\Fomvasss\AiTasks\Support\TenantResolver::class)->id();
                return new AiContext(
                    tenantId: $tenant,
                    taskName: $this->name(),
                    subjectType: null,
                    subjectId: null,
                    meta: ['locale' => $this->locale]
                );
            }

            public function toPayload(): AiPayload
            {
                return new AiPayload(
                    modality: $this->modality(),
                    messages: $this->messages,
                    options:  ['temperature' => $this->temperature],
                );
            }
        };

        // 4) Queue vs Sync
        if ($this->option('queue')) {
            $runId = $ai->queue($task, stage: 'request');
            $this->info("Queued. ai_runs.id={$runId}");
            return self::SUCCESS;
        }

        // 5) Sync
        try {
            $resp = $ai->send($task);

            if ($this->option('json')) {
                $this->line((string)($resp->content ?? ''));
                return self::SUCCESS;
            }

            $this->info('OK');
            $this->line('--- content ---');
            $this->line(Str::of($resp->content ?? '')->limit(10_000));

            if ($this->option('raw')) {
                $this->line('--- usage ---');
                $this->line(json_encode($resp->usage, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $this->line('--- raw ---');
                $this->line(json_encode($resp->raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            }
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array<int, array{role:string,content:string}> */
    protected function buildMessages(): array
    {
        if ($json = $this->option('messages')) {
            $arr = json_decode($json, true);

            return is_array($arr) ? $arr : [];
        }

        $prompt = (string) ($this->argument('prompt') ?? '');
        if ($prompt !== '') {
            return [['role' => 'user', 'content' => $prompt]];
        }

        return [];
    }
}
