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
    ) {}

    public function handle(): void
    {
        $run = AiRun::findOrFail($this->aiRunId);
        if ($run->status !== 'ok') return;

        $resp = new AiResponse(true, $run->response['content'] ?? null, $run->usage ?? [], $run->response ?? []);

        if (config('ai.postprocess.enabled')) {
            $resp = app(Pipeline::class)
                ->send($resp)->through(config('ai.postprocess.pipes', []))
                ->thenReturn();
        }

        if (! $this->taskClass || ! class_exists($this->taskClass)) {
            return;
        }

        $schemaKey = $run->request['schema'] ?? null;
        if ($schemaKey) {
            try {
                $parsed = \Fomvasss\AiTasks\Support\Schema::parse($resp->content ?? '', $schemaKey, strict: true);
                $resp->content = json_encode($parsed, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                $run->update(['status' => 'error', 'error' => "schema_error: " . $e->getMessage()]);
                event(new \Fomvasss\AiTasks\Events\AiRunPostprocessFailed($run, $e));
                return;
            }
        }

        /** @var class-string<AiTask> $cls */
        $cls = $this->taskClass;

        // 1) якщо є fromQueueArgs() — використовуємо
        if (is_subclass_of($cls, QueueSerializableAi::class) && method_exists($cls, 'fromQueueArgs')) {
            /** @var AiTask $task */
            $task = $cls::fromQueueArgs($this->taskCtorArgs);
        }
        // 2) якщо є serializeForQueue беккомпат — просто new ... (...$args)
        elseif (method_exists($cls, 'serializeForQueue')) {
            $task = new $cls(...$this->taskCtorArgs);
        }
        // 3) інакше — спроба прямої інстанціації (кине чітку помилку, якщо не сходиться)
        else {
            $task = new $cls(...$this->taskCtorArgs);
        }

        $result = $task->postprocess($resp);

        event(new \Fomvasss\AiTasks\Events\AiRunPostprocessed($run, $result));
    }
}
