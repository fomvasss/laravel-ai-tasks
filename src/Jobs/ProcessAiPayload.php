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
        public ?string $runId = null,              // ← нове
        public ?string $taskClass = null,
        public array $taskCtorArgs = [],
        public ?AiTask $taskInstance = null,
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
        $run = \Fomvasss\AiTasks\Models\AiRun::findOrFail($this->runId);
       
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $resp = $manager->driver($this->driverName)->send($this->payload, $this->context);

            if (!empty($resp->usage['async'])) {
                $run->update([
                    'status' => 'waiting',
                    'response' => array_merge($run->response ?? [], [
                        'provider_run_id' => $resp->usage['provider_run_id'] ?? null,
                        'webhook_token'   => $resp->usage['webhook_token'] ?? null,
                    ]),
                    'finished_at' => null,
                    'duration_ms' => null,
                ]);
                return; // continue wait webhook
            }

            if (! $resp->ok) {
                $ms = $run->started_at ? max(0, (int) now()->diffInMilliseconds($run->started_at, true)) : null;
                $run->update([
                    'status' => 'error',
                    'error' => $resp->error,
                    'usage' => $resp->usage,
                    'finished_at' => now(),
                    'duration_ms' => $ms,
                ]);
                $this->release($this->backoff[min($this->attempts()-1, count($this->backoff)-1)]);
                return;
            }

            $ms = $run->started_at ? max(0, (int) now()->diffInMilliseconds($run->started_at, true)) : null;
            $run->update([
                'status' => 'ok',
                'response' => \Fomvasss\AiTasks\Models\AiRun::storeResponse($resp),
                'usage' => $resp->usage,
                'finished_at' => now(),
                'duration_ms' => $ms,
            ]);

            dispatch(new \Fomvasss\AiTasks\Jobs\PostprocessAiResult(
                $run->id,
                $this->taskClass,
                $this->taskCtorArgs
            ))->onQueue(config('ai.queues.post'));

        } catch (\Throwable $e) {
            $ms = $run->started_at ? max(0, (int) now()->diffInMilliseconds($run->started_at, true)) : null;
            $run->update([
                'status' => 'error',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
                'duration_ms' => $ms,
            ]);
            throw $e;
        }
    }
}
