<?php

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Illuminate\Support\Facades\Http;

final class GeminiDriver implements AiDriver
{
    public function __construct(private array $cfg) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text','chat','image','vision','embed'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], 'driver_not_configured: gemini');
        }
        
        // Узагальнений варіант: content = messages[..].content
        $prompt = $p->messages[0]['content'] ?? '';

        $res = Http::acceptJson()
            ->withQueryParameters(['key' => $this->cfg['api_key']])
            ->post(rtrim($this->cfg['endpoint'],'/')."/v1beta/models/{$this->cfg['model']}:generateContent", [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => $p->options['temperature'] ?? 0.3
                ],
            ])->throw()->json();

        $msg = $res['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $usage = [
            'driver' => 'gemini',
            'tokens_in' => $res['usageMetadata']['promptTokenCount'] ?? null,
            'tokens_out'=> $res['usageMetadata']['candidatesTokenCount'] ?? null,
        ];

        return new AiResponse(true, $msg, $usage, $res);
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        return $this->send($p, $c);
    }

    public function queue(AiPayload $p, AiContext $c, ?string $queue = null): string
    {
        return dispatch(
            (new \Fomvasss\AiTasks\Jobs\ProcessAiPayload('gemini_flash', $p, $c))
                ->onQueue($queue ?? config('ai.queues.default'))
        )->id;
    }
}
