<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\QueueArgs;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(string $driver, Request $request, WebhookRegistry $registry): JsonResponse
    {
        if (! $registry->has($driver)) {
            return response()->json(['ok' => false, 'reason' => 'driver_webhook_not_registered'], 404);
        }

        $payload = ($registry->handler($driver))($request);

        $run = AiRun::query()
            ->where('status', 'waiting')
            ->where('driver', $driver)
            ->where('response->provider_run_id', $payload->providerRunId)
            ->latest()
            ->first();

        if (! $run) {
            return response()->json(['ok' => false, 'reason' => 'run_not_found'], 404);
        }

        if ($payload->status === 'succeeded') {
            $content = is_string($payload->content)
                ? $payload->content
                : json_encode($payload->content);

            $run->finish(new AiResponse(true, $content, $payload->usage));

            $taskClass = $run->request['task_class'] ?? null;
            $taskArgs  = $run->request['task_args'] ?? null;

            $canReconstruct = $taskClass && class_exists($taskClass) && (
                $taskArgs !== null
                || ((new \ReflectionClass($taskClass))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0
            );

            if ($canReconstruct) {
                dispatch(new PostprocessAiResult($run->id, $taskClass, QueueArgs::revive($taskArgs ?? [])))
                    ->onQueue(config('ai-tasks.queues.post'));
            } else {
                // run predates task_class storage, or task args weren't stored
                // (AI_STORE_REQUEST=false) — the run is finished, but postprocess()/
                // onCompleted() can't be executed without reconstructing the task
                Log::warning('ai-tasks webhook: run finished without postprocess (task not reconstructable)', [
                    'run_id' => $run->id,
                    'task_class' => $taskClass,
                ]);
            }

            return response()->json(['ok' => true]);
        }

        $run->fail($payload->error ?? 'webhook_failed', $payload->usage);

        return response()->json(['ok' => true]);
    }
}
