<?php

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\Pipes\EnsureJson;
use Fomvasss\AiTasks\Support\Pipes\QualityScore;
use Fomvasss\AiTasks\Support\Pipes\SanitizeHtml;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostprocessAiResult implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $aiRunId,
        public ?string $taskClass,
        public array $taskCtorArgs = [],
        public ?AiTask $taskInstance = null,
    ) {}

    public function handle(): void
    {
        $run = AiRun::findOrFail($this->aiRunId);
        if ($run->status !== 'ok') return;

        $resp = new AiResponse(true, $run->response['content'] ?? null, $run->usage ?? [], $run->response ?? []);
        
        $resp = app(Pipeline::class)->send($resp)->through([
            EnsureJson::class,    // якщо очікується JSON
            SanitizeHtml::class,  // очистка HTML
            QualityScore::class,  // скоринг
        ])->thenReturn();

        $schemaKey = $run->request['schema'] ?? null;
        if ($schemaKey) {
            try {
                $parsed = \Fomvasss\AiTasks\Support\Schema::parse($resp->content ?? '', $schemaKey, strict: true);
                $resp->content = json_encode($parsed, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                // маркуй як помилку схеми або залиш як є — залежить від політики
                $run->update(['status'=>'error','error'=>"schema_error: ".$e->getMessage()]);
                return;
            }
        }

        if ($this->taskClass && class_exists($this->taskClass)) {
            /** @var AiTask $task */
            $task = $this->taskInstance ?:
                \Fomvasss\AiTasks\Support\QueueSerializer::instantiate($this->taskClass, $this->taskCtorArgs);
            
            $task->postprocess($resp);
        }
    }
}
