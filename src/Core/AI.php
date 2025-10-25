<?php

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Arr;

class AI
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly Router $router
    ) {}

    public function send(AiTask $task, array|string $drivers = []): AiResponse
    {
        $payload = $task->toPayload();
        $ctx     = $task->context();

        if ($drivers) {
            $drivers = is_string($drivers) ? [$drivers] : $drivers;
            $list = $drivers;
        } else {
            $list = $this->router->choose($task);
        }

        $errors = [];
        foreach ($list as $driverName) {
            
            $run = AiRun::start($driverName, $payload, $ctx, $task);

            $cfg = config("ai.drivers.$driverName");
            $api = $cfg['api_key'] ?? null;
            if (! $api || trim($api) === '') {
                $run->skip('driver_not_configured: ' . $driverName);
                continue;
            }

            //try {
                $resp = $this->manager->driver($driverName)->send($payload, $ctx);

                if ($resp->error === 'async_pending') {
                    $run->markWaiting(['provider_run_id' => $resp->raw['provider_run_id'] ?? null]);
                }

                if (! $resp->ok) {
                    $run->fail($resp->error, $resp->usage);
                    $errors[] = "$driverName: {$resp->error}";
                    continue;
                }
                
                $run->finish($resp);

                if (config('ai.postprocess.enabled')) {
                    $resp = app(Pipeline::class)
                        ->send($resp)->through(config('ai.postprocess.pipes', []))
                        ->thenReturn();
                }

//                try {
                    $result = $task->postprocess($resp);
                
                    event(new \Fomvasss\AiTasks\Events\AiRunPostprocessed($run, $result));
                
                    return $result instanceof AiResponse ? $result : new AiResponse(true, json_encode($result), usage: []);

//                } catch (\Throwable $e) {
//                    $run->error($e);
//                    $errors[] = "$driverName postprocess: ".$e->getMessage();
//                    continue;
//                }
                
                throw new \RuntimeException('All providers failed: '.implode(' | ', $errors));

//            } catch (\Throwable $e) {
//                $run->error($e);
//                continue;
//            }
        }

        throw new \RuntimeException('All providers failed');
    }

    public function queue(AiTask $task, ?AiContext $ctx = null, string $stage = 'request', array|string $drivers=[]): string
    {
        $payload = $task->toPayload();
        $ctx     = $ctx ?? $task->context();
        $list    = $drivers ? (is_string($drivers) ? [$drivers] : $drivers) : $this->router->choose($task);
        $driver  = $list[0];
        
        if ($task instanceof \Fomvasss\AiTasks\Contracts\QueueSerializableAi) {
            $ctorArgs = $task->toQueueArgs();
        } elseif (method_exists($task, 'serializeForQueue')) {
            $ctorArgs = $task->serializeForQueue();
        } else {
            throw new \RuntimeException(
                'Task '.get_class($task).' must implement QueueSerializableAi::toQueueArgs() '.
                'or has method serializeForQueue().'
            );
        }

        $run = AiRun::startAsQueue($driver, $payload, $ctx, $task);
        
        $job = new \Fomvasss\AiTasks\Jobs\ProcessAiPayload(
            driverName: $driver,
            payload: $payload,
            context: $ctx,
            idempotencyKey: $task->idempotencyKey(),
            taskName: $task->name(),
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $ctorArgs,
        );

        if ($task instanceof \Fomvasss\AiTasks\Contracts\ShouldQueueAi) {
            if ($conn = $task->preferredConnection()) {
                $job->onConnection($conn);
            }
            $job->onQueue($task->preferredQueueFor($stage, config('ai.queues.default')));
        } else {
            $job->onQueue(config('ai.queues.default'));
        }
        
        dispatch($job);

        return $run->id;
    }

    public function stream(AiTask $task, callable $onChunk, array|string $drivers = []): AiResponse
    {
        $payload = $task->toPayload();
        $ctx     = $task->context();

        if ($drivers) {
            $drivers = is_string($drivers) ? [$drivers] : $drivers;
            $list = $drivers;
        } else {
            $list = $this->router->choose($task);
        }

        $errors = [];
        foreach ($list as $driverName) {
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
