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
        public ?string $taskClass
    ) {}

    public function handle(): void
    {
        $run = AiRun::findOrFail($this->aiRunId);
        if ($run->status !== 'ok') return;

        $resp = new AiResponse(true, $run->response['content'] ?? null, $run->usage ?? [], $run->response ?? []);

        // простий пайплайн
        $resp = app(Pipeline::class)->send($resp)->through([
            EnsureJson::class,    // якщо очікується JSON
            SanitizeHtml::class,  // очистка HTML
            QualityScore::class,  // скоринг
        ])->thenReturn();

        if ($this->taskClass && class_exists($this->taskClass)) {
            /** @var AiTask $task */
            $task = app($this->taskClass);
            $task->postprocess($resp);
        }
    }
}
