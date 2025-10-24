<?php

namespace Fomvasss\AiTasks\Jobs;

use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessAiPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public array $backoff = [10, 30, 120];

    public function __construct(
        public string $driverName,
        public \Fomvasss\AiTasks\DTO\AiPayload $payload,
        public \Fomvasss\AiTasks\DTO\AiContext $context,
        public ?string $idempotencyKey = null,
        public ?string $taskName = null,
        public ?string $runId = null,
        public ?string $taskClass = null,
        public array $taskCtorArgs = [],
    ) {}

    public function middleware(): array
    {
        return [
            new \Illuminate\Queue\Middleware\RateLimited("ai:{$this->context->tenantId}:{$this->driverName}"),
            new \Illuminate\Queue\Middleware\WithoutOverlapping('ai:lock:'.$this->idempotencyKey),
        ];
    }

    public function handle(\Fomvasss\AiTasks\Core\AiManager $manager): void
    {
        /** @var AiRun $run */
        $run = \Fomvasss\AiTasks\Models\AiRun::findOrFail($this->runId);

        if ($run->status !== 'running') {
            $run->markRunning(); // -> AiRunStarted
        }

        try {
            $resp = $manager->driver($this->driverName)->send($this->payload, $this->context);

            if (!empty($resp->usage['async_pending'])) {
                
                $run->markWaiting([
                    'provider_run_id' => $resp->usage['provider_run_id'] ?? null,
                    'webhook_token'   => $resp->usage['webhook_token'] ?? null,
                ]);
                
                return; // continue wait webhook
            }

            if (! $resp->ok) {
                
                $run->fail($resp->error, $resp->usage);
                $this->release($this->backoff[min($this->attempts()-1, count($this->backoff)-1)]);
                return;
            }
            
            $run->finish($resp);

            dispatch(new \Fomvasss\AiTasks\Jobs\PostprocessAiResult(
                $run->id,
                $this->taskClass,
                $this->taskCtorArgs,
            ))->onQueue(config('ai.queues.post'));

        } catch (\Throwable $e) {
            $run->error($e);
            throw $e;
        }
    }
}
