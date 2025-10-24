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

        if ($p->modality === 'embed') {
            return $this->sendEmbed($p, $c);
        }

        if ($p->modality === 'vision') {
            return $this->sendVision($p, $c);
        }
        
        return $this->sendTextAndChat($p, $c);
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

    protected function sendTextAndChat(AiPayload $p, AiContext $c): AiResponse
    {
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

    protected function sendEmbed(AiPayload $p, AiContext $c):AiResponse
    {
        $model = $this->cfg['embed_model'] ?? 'text-embedding-004';

        // Gemini embeddings: POST /v1beta/models/{model}:embedContent
        // input: рядок або масив рядків — якщо масив, зробимо простий батч (по одному запиту), щоб не ускладнювати
        $input = $p->messages[0]['content'] ?? ($p->options['input'] ?? null);
        if ($input === null) {
            return new AiResponse(false, null, [], [], 'embed_input_missing');
        }

        $doEmbed = function(string $text) use ($model) {
            $url = rtrim($this->cfg['endpoint'],'/') . "/v1beta/models/{$model}:embedContent";
            $payload = [
                'model' => $model,
                'content' => ['parts' => [['text' => $text]]],
            ];
            $res = Http::withHeaders(['x-goog-api-key' => $this->cfg['api_key']])
                ->acceptJson()
                ->post($url, $payload)
                ->throw()
                ->json();

            // очікувано: embedding.values (масив чисел)
            return $res['embedding']['values'] ?? [];
        };

        if (is_array($input)) {
            $vectors = [];
            foreach ($input as $txt) $vectors[] = $doEmbed((string)$txt);
            $content = $vectors;
        } else {
            $content = $doEmbed((string)$input);
        }

        return new AiResponse(true, json_encode($content), ['driver'=>'gemini','model'=>$model], []);
    }

    protected function sendVision(AiPayload $p, AiContext $c):AiResponse
    {
        $model = $this->cfg['model'] ?? 'gemini-1.5-flash';

        // Збираємо parts:
        // - text → {text:'...'}
        // - image (url або base64) → або fileData/inline_data
        $parts = [];
        $contentParts = $p->messages[0]['content'] ?? [];

        foreach ($contentParts as $part) {
            if (($part['type'] ?? null) === 'text') {
                $parts[] = ['text' => (string)($part['text'] ?? '')];
            } elseif (($part['type'] ?? null) === 'image_url') {
                // Gemini не приймає чужі URL напряму як image_url; треба fileData або inline_data.
                // Найпростіше для демо: стягнути вміст і покласти як inline_data (з обережністю у проді).
                $url = $part['image_url']['url'] ?? null;
                if ($url) {
                    try {
                        $bin = Http::get($url)->throw()->body();
                        $b64 = base64_encode($bin);
                        $mime = 'image/png'; // або вирахуй за Content-Type
                        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
                    } catch (\Throwable $e) { /* ігноруємо цю частину */ }
                }
            } elseif (($part['type'] ?? null) === 'inline_base64') {
                // наш умовний тип: {type:'inline_base64', mime:'image/png', data:'...'}
                $mime = $part['mime'] ?? 'image/png';
                $data = $part['data'] ?? '';
                $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $data]];
            }
        }

        if (empty($parts)) {
            return new AiResponse(false, null, [], [], 'vision_parts_missing');
        }

        $url = rtrim($this->cfg['endpoint'], '/') . "/v1beta/models/{$model}:generateContent";
        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => $p->options['temperature'] ?? 0.3,
            ],
        ];

        $res = Http::withHeaders(['x-goog-api-key' => $this->cfg['api_key']])
            ->acceptJson()
            ->post($url, $payload)
            ->throw()
            ->json();

        $msg = $res['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return new AiResponse((bool)$msg, $msg, ['driver'=>'gemini','model'=>$model], $res, $msg ? null : 'empty_vision_response');
    }
}
