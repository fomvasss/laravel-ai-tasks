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

        $errors = [];
     
        foreach ($this->router->choose($task) as $driverName) {
            
            $run = AiRun::start($driverName, $payload, $ctx, $task);
    
            //try {
                $resp = $this->manager->driver($driverName)->send($payload, $ctx);

                if (! $resp->ok) {
                    $run->fail($resp->error, $resp->usage);
                    $errors[] = "$driverName: {$resp->error}";
                    continue;
                }

                $run->finish($resp);
                try {
                    $resp = $task->postprocess($resp);

                    return $resp instanceof AiResponse ? $resp : new AiResponse(true, json_encode($resp), usage: []);

                } catch (\Throwable $e) {
                    $run->error($e);
                    $errors[] = "$driverName postprocess: ".$e->getMessage();
                    continue;
                }
                
                throw new \RuntimeException('All providers failed: '.implode(' | ', $errors));

//            } catch (\Throwable $e) {
//                $run->error($e);
//                continue;
//            }
        }

        throw new \RuntimeException('All providers failed');
    }

    public function queue(AiTask $task, ?AiContext $ctx = null, string $stage = 'request'): string
    {
        $payload = $task->toPayload();
        $ctx     = $ctx ?? $task->context();
        $driver  = $this->router->first($task);
        
        $run = \Fomvasss\AiTasks\Models\AiRun::create([
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

        // Auto-get of constructor arguments
        $ctorArgs = \Fomvasss\AiTasks\Support\QueueSerializer::serializeTask($task);

        $job = new \Fomvasss\AiTasks\Jobs\ProcessAiPayload(
            driverName: $driver,
            payload: $payload,
            context: $ctx,
            idempotencyKey: $task->idempotencyKey(),
            taskName: $task->name(),
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $ctorArgs,
            taskInstance: $task,
        );

        if ($task instanceof \Fomvasss\AiTasks\Contracts\ShouldQueueAi) {
            if ($conn = $task->preferredConnection()) $job->onConnection($conn);
            $job->onQueue($task->preferredQueueFor($stage, config('ai.queues.default')));
        } else {
            $job->onQueue(config('ai.queues.default'));
        }

        \Log::debug('AI::queue ctorArgs', [
            'task' => get_class($task),
            'args' => \Fomvasss\AiTasks\Support\QueueSerializer::serializeTask($task),
        ]);
        
        dispatch($job);

        return $run->id;
    }

    public function stream(AiTask $task, callable $onChunk): AiResponse
    {
        $payload = $task->toPayload();
        $ctx     = $task->context();

        $errors = [];
        foreach ($this->router->choose($task) as $driverName) {
            try {
                $driver = $this->manager->driver($driverName);
                $resp = $driver->stream($payload, $ctx, $onChunk);

                // post-processing after the stream is complete (if necessary)
                try {
                    $out = $task->postprocess($resp);
                    return $out instanceof AiResponse ? $out : new \Fomvasss\AiTasks\DTO\AiResponse(true, json_encode($out));
                } catch (\Throwable $e) {
                    $errors[] = "{$driverName} postprocess: " . $e->getMessage();
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$driverName}: ".$e->getMessage();
                continue;
            }
        }
        throw new \RuntimeException('All providers failed: '.implode(' | ', $errors));
    }
}
