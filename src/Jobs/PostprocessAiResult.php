<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostprocessAiResult implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $aiRunId,
        public readonly string  $taskClass,
        public readonly array   $taskCtorArgs = [],
    ) {}

    public function handle(): void
    {
        $run = AiRun::findOrFail($this->aiRunId);

        if ($run->status !== 'ok') {
            return;
        }

        $resp = new AiResponse(
            ok:      true,
            content: $run->response['content'] ?? null,
        );

        /** @var class-string<AiTask> $cls */
        $cls  = $this->taskClass;
        $task = $cls::fromQueueArgs($this->taskCtorArgs);

        $result = $task->postprocess($resp);

        $finalResponse = $result instanceof AiResponse
            ? $result
            : new AiResponse(true, json_encode($result));

        event(new AiTaskCompleted($task, $finalResponse, $run));
    }
}
