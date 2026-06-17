<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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

            dispatch(new PostprocessAiResult($run->id, $run->task, []))
                ->onQueue(config('ai.queues.post'));

            return response()->json(['ok' => true]);
        }

        $run->fail($payload->error ?? 'webhook_failed', $payload->usage);

        return response()->json(['ok' => true]);
    }
}
