<?php

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DynamicWebhookController extends Controller
{
    public function handle(string $driver, Request $request, WebhookRegistry $registry)
    {
        if (! $registry->has($driver)) {
            return response()->json(['ok' => false, 'reason' => 'driver_webhook_not_registered'], 404);
        }

        // delegate driver handler
        $payload = ($registry->handler($driver))($request);

        // find run with status waiting and provider_run_id
        $run = AiRun::query()
            ->where('status', 'waiting')
            ->where('driver', $driver)
            ->where('response->provider_run_id', $payload->providerRunId)
            ->latest()->first();

        if (! $run) {
            return response()->json(['ok' => false, 'reason' => 'run_not_found'], 404);
        }

        $ms = $run->started_at ? max(0, (int) now()->diffInMilliseconds($run->started_at, true)) : null;

        if ($payload->status === 'succeeded') {
            $resp = new AiResponse(true, is_string($payload->content) ? $payload->content : json_encode($payload->content), $payload->usage, []);
            $run->update([
                'status'      => 'ok',
                'response'    => array_merge($run->response ?? [], ['content' => $resp->content]),
                'usage'       => array_merge($run->usage ?? [], $payload->usage),
                'finished_at' => now(),
                'duration_ms' => $ms,
            ]);

            dispatch(new \Fomvasss\AiTasks\Jobs\PostprocessAiResult($run->id, null))
                ->onQueue(config('ai.queues.post'));

            return response()->json(['ok' => true]);
        }

        // failed/canceled
        $run->update([
            'status'      => 'error',
            'error'       => $payload->error ?: 'webhook_failed',
            'usage'       => array_merge($run->usage ?? [], $payload->usage),
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);

        return response()->json(['ok' => true]);
    }
}