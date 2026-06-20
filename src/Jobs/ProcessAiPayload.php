<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Events\AiTaskFailed;
use Fomvasss\AiTasks\Events\AiTaskStarted;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessAiPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $timeout = 300;
    public int   $tries   = 3;
    public array $backoff = [10, 30, 120];

    public function __construct(
        public readonly string    $driverName,
        public readonly AiPayload $payload,
        public readonly AiContext $context,
        public readonly string    $runId,
        public readonly string    $taskClass,
        public readonly array     $taskCtorArgs = [],
        int                       $timeout = 300,
    ) {
        $this->timeout = $timeout;
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping("ai:run:{$this->runId}"),
        ];
    }

    public function handle(AiManager $manager): void
    {
        $run  = AiRun::findOrFail($this->runId);
        $task = $this->resolveTask();

        if (! $task->shouldRun()) {
            $run->skip('guard_rejected');
            return;
        }

        $run->markRunning();

        try {
            app(Budget::class)->ensureNotExceeded($this->context->tenantId);

            event(new AiTaskStarted($task, $this->context, $run));

            $resp = $manager->driver($this->driverName)->send($this->payload, $this->context);

            if (! $resp->ok) {
                $run->fail($resp->error ?? 'unknown_error');
                event(new AiTaskFailed($task, $resp->error ?? 'unknown_error', $run));
                return;
            }

            app(Budget::class)->ensureNotExceeded($this->context->tenantId, (float) ($resp->usage['cost'] ?? 0.0));

            $run->finish($resp);

            $resp = $this->runPostprocess($resp);

            dispatch(new PostprocessAiResult($run->id, $this->taskClass, $this->taskCtorArgs))
                ->onQueue(config('ai-tasks.queues.post'));

        } catch (BudgetExceededException $e) {
            $run->fail($e->getMessage());
            event(new AiTaskFailed($task, $e->getMessage(), $run));

        } catch (\Throwable $e) {
            AiRun::markAsDead($this->runId, $e);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        AiRun::markAsDead($this->runId, $e);
    }

    private function resolveTask(): AiTask
    {
        /** @var class-string<AiTask> $cls */
        $cls = $this->taskClass;

        return $cls::fromQueueArgs($this->taskCtorArgs);
    }

    private function runPostprocess(mixed $resp): mixed
    {
        if (! config('ai-tasks.postprocess.enabled', false)) {
            return $resp;
        }

        return app(Pipeline::class)
            ->send($resp)
            ->through(config('ai-tasks.postprocess.pipes', []))
            ->thenReturn();
    }
}
