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

        if ($p->modality === 'image') {
            return $this->sendImage($p, $c);
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
        // TODO
        return $this->send($p, $c);
    }

    public function queue(AiPayload $p, AiContext $c, ?string $queue = null): string
    {
        return dispatch(
            (new \Fomvasss\AiTasks\Jobs\ProcessAiPayload('gemini_flash', $p, $c))
                ->onQueue($queue ?? config('ai.queues.default'))
        )->id;
    }

    protected function sendImage(AiPayload $p, AiContext $c): AiResponse
    {
        $prompt = $p->messages[0]['content'] ?? '';
        $n      = (int)($p->options['n'] ?? 1);

        // Модель Imagen (див. конфіг/ENV)
        $model  = $this->cfg['imagen_model'] ?? 'imagen-4.0-generate-001';

        // Опційні параметри Imagen (мапимо з options)
        // imageSize: '1K' | '2K' (тільки для Standard/Ultra), aspectRatio: '1:1','3:4','4:3','9:16','16:9'
        $imageSize   = $p->options['image_size']    ?? null;   // приклад: '1K' або '2K'
        $aspectRatio = $p->options['aspect_ratio']  ?? null;   // приклад: '1:1'
        $personGen   = $p->options['person_generation'] ?? null; // 'dont_allow'|'allow_adult'|'allow_all'
        // нота: 'allow_all' недоступний у EU/UK/CH/MENA (див. доки)

        // Збір тіла запиту (відкидаємо null поля)
        $params = array_filter([
            'sampleCount'     => $n,
            'imageSize'       => $imageSize,
            'aspectRatio'     => $aspectRatio,
            'personGeneration'=> $personGen,
        ], static fn($v) => !is_null($v));

        $body = [
            'instances'  => [['prompt' => $prompt]],
            'parameters' => (object)$params,
        ];

        $url = rtrim($this->cfg['endpoint'], '/')
            . "/v1beta/models/{$model}:predict";

        // Виклик REST (Gemini API / Imagen)
        $res = \Illuminate\Support\Facades\Http::withHeaders([
            'x-goog-api-key' => $this->cfg['api_key'],
        ])
            ->acceptJson()
            ->post($url, $body)
            ->throw(function ($resp, $e) {
                // залишаємо помилку для нижчої обробки
            })
            ->json();

        // RESP ФОРМАТИ (покриваємо можливі варіанти)
        // SDK показує generatedImages[*].image.imageBytes (base64).
        // REST часто повертає predictions[*].bytesBase64Encoded.
        $firstB64 = null;

        // варіант 1: generatedImages
        if (isset($res['generatedImages'][0]['image']['imageBytes'])) {
            $firstB64 = $res['generatedImages'][0]['image']['imageBytes'];
        }
        // варіант 2: predictions
        if (!$firstB64 && isset($res['predictions'][0]['bytesBase64Encoded'])) {
            $firstB64 = $res['predictions'][0]['bytesBase64Encoded'];
        }
        // варіант 3: інші можливі форми (підстрахуємось)
        if (!$firstB64 && isset($res['predictions'][0]['image']['imageBytes'])) {
            $firstB64 = $res['predictions'][0]['image']['imageBytes'];
        }

        return new AiResponse(
            ok: (bool)$firstB64,
            content: $firstB64,  // base64 PNG (найчастіше)
            usage: [
                'driver' => 'gemini',
                'images' => $n,
                'model'  => $model,
                'params' => $params,
            ],
            raw: $res,
            error: $firstB64 ? null : ($res['error']['message'] ?? 'empty_image_response')
        );
    }
}
