<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskFailed;
use Fomvasss\AiTasks\Events\AiTaskStarted;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\Budget;
use Fomvasss\AiTasks\Support\Failover;
use Fomvasss\AiTasks\Support\QueueDispatch;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $timeout = 300;
    public int   $tries   = 3;
    public array $backoff = [10, 30, 120];

    /**
     * Наступні драйвери routing-ланцюжка після $driverName. Звичайна властивість зі значенням
     * за замовчуванням, а не promoted readonly: job, поставлений у чергу до появи поля,
     * десеріалізується з [] і працює як раніше — лише з одним драйвером.
     *
     * @var list<string>
     */
    public array $fallbackDrivers = [];

    public function __construct(
        public readonly string    $driverName,
        public readonly AiPayload $payload,
        public readonly AiContext $context,
        public readonly string    $runId,
        public readonly string    $taskClass,
        public readonly array     $taskCtorArgs = [],
        int                       $timeout = 300,
        public readonly int       $attempt = 0,
        array                     $fallbackDrivers = [],
    ) {
        $this->timeout         = $timeout;
        $this->fallbackDrivers = $fallbackDrivers;
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

            $resp = $this->sendThroughChain($manager, $run);

            if (! $resp->ok) {
                $run->fail($resp->error ?? 'unknown_error');
                event(new AiTaskFailed($task, $resp->error ?? 'unknown_error', $run));
                AI::failFinally($task, $resp->error ?? 'unknown_error', $run);
                return;
            }

            app(Budget::class)->ensureNotExceeded($this->context->tenantId, (float) ($resp->usage['cost'] ?? 0.0));

            $run->finish($resp);

            $post = new PostprocessAiResult($run->id, $this->taskClass, $this->taskCtorArgs, $this->attempt);

            QueueDispatch::configure($post, $task, 'post', config('ai-tasks.queues.post'));

            dispatch($post);

        } catch (BudgetExceededException $e) {
            // Якщо $resp вже відомий — виклик провайдера відбувся й оплачений: статус чесний
            // ('error'), але cost зберігається через fail($usage), щоб витрата не випала з
            // майбутніх підрахунків бюджету (issue #7): Budget сумує по cost, не по status.
            $run->fail($e->getMessage(), isset($resp) && $resp->ok ? $resp->usage : []);
            event(new AiTaskFailed($task, $e->getMessage(), $run));
            AI::failFinally($task, $e, $run);

        } catch (\Throwable $e) {
            // Лише помилка цієї спроби: статус лишається 'running', а 'dead', AiRunFailed і
            // onFailed() — один раз у failed(), коли черга вичерпала спроби. Інакше вони
            // спрацьовували б на кожній спробі, і прогін між ними стрибав би dead → running.
            $run->update(['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Пробує драйвери ланцюжка по черзі в межах однієї спроби job'а — як AI::send(). На
     * наступний переходить лише після транзиєнтної помилки (Failover::shouldTryNext()) або
     * відповіді !ok; останній виняток прокидається далі, і job ретраїть уже весь ланцюжок.
     * ai_runs.driver — той драйвер, що відповів: вартість і модель рахуються по ньому.
     */
    private function sendThroughChain(AiManager $manager, AiRun $run): AiResponse
    {
        $drivers = [$this->driverName, ...$this->fallbackDrivers];
        $last    = array_key_last($drivers);

        foreach ($drivers as $i => $driverName) {
            if ($run->driver !== $driverName) {
                $run->update(['driver' => $driverName]);
            }

            try {
                $resp = $manager->driver($driverName)->send($this->payload, $this->context);
            } catch (\Throwable $e) {
                if ($i === $last || ! Failover::shouldTryNext($e)) {
                    throw $e;
                }

                Log::warning('AI driver failed, trying next in chain', [
                    'run_id' => $run->id,
                    'driver' => $driverName,
                    'next'   => $drivers[$i + 1],
                    'error'  => $e->getMessage(),
                ]);

                continue;
            }

            if ($resp->ok) {
                return $resp;
            }
        }

        return $resp;
    }

    public function failed(\Throwable $e): void
    {
        AiRun::markAsDead($this->runId, $e);

        try {
            $task = $this->resolveTask();
        } catch (\Throwable $resolveError) {
            Log::error('AiTask could not be rebuilt for onFailed()', [
                'run_id' => $this->runId,
                'task' => $this->taskClass,
                'exception' => $resolveError->getMessage(),
            ]);

            return;
        }

        AI::failFinally($task, $e, AiRun::find($this->runId));
    }

    private function resolveTask(): AiTask
    {
        /** @var class-string<AiTask> $cls */
        $cls = $this->taskClass;

        return $cls::fromQueueArgs($this->taskCtorArgs);
    }
}
