<?php

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Arr;

class AI
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly Router $router
    ) {}

    public function send(AiTask $task): AiResponse
    {
        $payload = $task->toPayload();
        $ctx     = $task->context();

        foreach ($this->router->choose($task) as $driverName) {
            $run = AiRun::start($driverName, $payload, $ctx, $task);
            try {
                $resp = $this->manager->driver($driverName)->send($payload, $ctx);

                if (! $resp->ok) {
                    $run->fail($resp->error, $resp->usage);
                    continue;
                }

                $run->finish($resp);
                $resp = $task->postprocess($resp);

                return $resp instanceof AiResponse ? $resp : new AiResponse(true, json_encode($resp), usage: []);
            } catch (\Throwable $e) {
                $run->error($e);
                continue;
            }
        }

        throw new \RuntimeException('All providers failed');
    }

    public function queue(AiTask $task, string $stage = 'request'): string
    {
        $payload = $task->toPayload();
        $ctx     = $task->context();
        $driver  = $this->router->first($task);

        // створюємо запис у БД зі статусом "queued"
        $run = \Fomvasss\AiTasks\Models\AiRun::create([
            'id'            => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id'     => $ctx->tenantId,
            'task'          => $ctx->taskName,
            'driver'        => $driver,
            'modality'      => $payload->modality,
            'subject_type'  => $ctx->subjectType,
            'subject_id'    => $ctx->subjectId,
            'status'        => 'queued',
            'idempotency_key'=> $task->idempotencyKey(),
            'request'       => \Fomvasss\AiTasks\Models\AiRun::minifyRequest($payload),
            'started_at'    => null,
            'finished_at'   => null,
            'duration_ms'   => null,
        ]);

        $job = new \Fomvasss\AiTasks\Jobs\ProcessAiPayload(
            driverName: $driver,
            payload: $payload,
            context: $ctx,
            idempotencyKey: $task->idempotencyKey(),
            taskName: $task->name(),
            runId: $run->id,                 // ← передаємо наш run_id
            taskClass: $task::class
        );

        if ($task instanceof \Fomvasss\AiTasks\Contracts\ShouldQueueAi) {
            if ($conn = $task->preferredConnection()) $job->onConnection($conn);
            $job->onQueue($task->preferredQueueFor($stage, config('ai.queues.default')));
        } else {
            $job->onQueue(config('ai.queues.default'));
        }

        dispatch($job);                      // НЕ очікуємо id від PendingDispatch

        return $run->id;                     // повертаємо свій стабільний ідентифікатор
    }
}
