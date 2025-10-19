<?php

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Illuminate\Support\Facades\Http;

final class OpenAiDriver implements AiDriver
{
    public function __construct(private array $cfg) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text','chat','image','vision','embed'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], 'driver_not_configured: openai');
        }

        // chat/text приклад
        $res = Http::withToken($this->cfg['api_key'])
            ->acceptJson()
            ->post(rtrim($this->cfg['endpoint'],'/').'/chat/completions', [
                'model' => $this->cfg['model'],
                'messages' => $p->messages,
                'temperature' => $p->options['temperature'] ?? 0.3,
                'response_format' => $p->schema ? ['type'=>'json_object'] : null,
            ])->throw()->json();

        $msg = $res['choices'][0]['message']['content'] ?? null;
        $usage = $res['usage'] ?? [];
        $usage['driver'] = 'openai';

        return new AiResponse(true, $msg, $usage, $res);
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        // спрощено: без реального SSE
        return $this->send($p, $c);
    }

    public function queue(AiPayload $p, AiContext $c, ?string $queue = null): string
    {
        return dispatch(
            (new \Fomvasss\AiTasks\Jobs\ProcessAiPayload('openai_gpt4o', $p, $c))
                ->onQueue($queue ?? config('ai.queues.default'))
        )->id;
    }
}
