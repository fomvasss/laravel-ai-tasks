<?php

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Driver for Google Gemini API
 *
 * @see https://ai.google.dev/gemini-api/docs
 */
final class GeminiDriver implements AiDriver
{
    public function __construct(private array $cfg) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text', 'chat', 'image', 'vision', 'embed'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            \Log::info('GeminiDriver: API key not configured', [$this->cfg]);
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
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], 'driver_not_configured: openai');
        }

//        $body = [
//            'model' => $this->cfg['model'],
//            'messages' => $p->messages,
//            'temperature' => $p->options['temperature'] ?? 0.3,
//            'stream' => true,
//        ];
//
//        if ($p->schema) {
//            $body['response_format'] = ['type' => 'json_object'];
//        }
//
//        if (!empty($p->options['tools'])) {
//            $body['tools'] = $p->options['tools'];
//            if (isset($p->options['tool_choice'])) {
//                $body['tool_choice'] = $p->options['tool_choice'];
//            }
//        }

        //v1
        // 1) Будуємо endpoint streamGenerateContent
//        $base = rtrim($this->cfg['endpoint'], '/'); // напр. https://generativelanguage.googleapis.com
//        $url  = "{$base}/v1beta/models/{$this->cfg['model']}:streamGenerateContent";
//
//        // messages -> contents
//        $roleMap = ['user' => 'user', 'assistant' => 'model', 'system' => 'user'];
//        $contents = [];
//        foreach ($p->messages as $m) {
//            $role = $roleMap[$m['role'] ?? 'user'] ?? 'user';
//            $text = (string) ($m['content'] ?? '');
//            if ($text !== '') $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
//        }
//        if (!$contents) {
//            $prompt = $p->messages[0]['content'] ?? '';
//            $contents = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
//        }
//
//        $response = \Illuminate\Support\Facades\Http::accept('text/event-stream')
//            ->withQueryParameters([
//                'key' => $this->cfg['api_key'],
//                'alt' => 'sse', // важливо для коректного SSE
//            ])
//            ->withOptions([
//                'stream'       => true,
//                'read_timeout' => 0,
//            ])
//            ->post($url, [
//                'contents' => $contents,
//                'generationConfig' => [
//                    'temperature' => $p->options['temperature'] ?? 0.3,
//                ],
//            ]);
//
//        $body = $response->toPsrResponse()->getBody();
//        $buf  = '';
//        $full = '';
//
//        $emitDelta = function (string $delta) use (&$full, $onChunk) {
//            if ($delta === '') return;
//            $full .= $delta;
//            $onChunk($delta);
//        };
//
//        $parseLine = function (string $line) use ($emitDelta) {
//            $line = trim($line);
//            if ($line === '') return;
//
//            // Підтримати обидва формати: "data: {...}" та чистий JSON "{...}"
//            if (str_starts_with($line, 'data:')) {
//                $line = trim(substr($line, 5));
//            }
//
//            if ($line === '[DONE]') {
//                // кінець стріму
//                return 'DONE';
//            }
//
//            if ($line[0] !== '{' && $line[0] !== '[') {
//                return; // службові рядки/порожні
//            }
//
//            $event = json_decode($line, true);
//            if (!is_array($event)) return;
//
//            // Витяг тексту (може прийти як content.parts[].text або delta.text)
//            $delta =
//                $event['candidates'][0]['content']['parts'][0]['text']
//                ?? $event['candidates'][0]['delta']['text']
//                ?? '';
//
//            $emitDelta($delta);
//        };
//
//        while (! $body->eof()) {
//            $chunk = $body->read(8192);
//            if ($chunk === '') { usleep(10000); continue; }
//
//            $buf .= $chunk;
//
//            // ріжемо по \n (SSE/NDJSON)
//            while (($pos = strpos($buf, "\n")) !== false) {
//                $line = substr($buf, 0, $pos);
//                $buf  = substr($buf, $pos + 1);
//
//                $res = $parseLine($line);
//                if ($res === 'DONE') {
//                    // очищення буфера від можливого \r після DONE
//                    $buf = '';
//                    break 2;
//                }
//            }
//        }
//
//        return new \Fomvasss\AiTasks\DTO\AiResponse(true, $full, usage: [], raw: []);
        
        //v2
        $base = rtrim($this->cfg['endpoint'], '/'); // напр. https://generativelanguage.googleapis.com
        $url  = "{$base}/v1beta/models/{$this->cfg['model']}:streamGenerateContent";

        // messages -> contents (Gemini: role user|model, parts[{text}])
        $roleMap  = ['user' => 'user', 'assistant' => 'model', 'system' => 'user'];
        $contents = [];
        foreach ($p->messages as $m) {
            $role = $roleMap[$m['role'] ?? 'user'] ?? 'user';
            $text = (string) ($m['content'] ?? '');
            if ($text !== '') $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        if (!$contents) {
            $prompt   = (string)($p->messages[0]['content'] ?? '');
            $contents = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        }

        $response = \Illuminate\Support\Facades\Http::accept('text/event-stream')
            ->withQueryParameters([
                'key' => $this->cfg['api_key'],
                'alt' => 'sse', // деяким середовищам потрібно явно
            ])
            ->withOptions([
                'stream'       => true,
                'read_timeout' => 0,
            ])
            ->post($url, [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $p->options['temperature'] ?? 0.3,
                    // 'maxOutputTokens' => 1024, // опційно
                ],
                // systemInstruction/safetySettings — за потреби
            ]);

        $body = $response->toPsrResponse()->getBody();

        $full = '';
        $buf  = '';

        // Симульоване дрібнення дельт (коли Gemini шле великими шматками)
        $emitSplit = function (string $text) use (&$full, $onChunk, $p) {
            if ($text === '') return;

            $full .= $text;

            $pack    = (int)($p->options['simulate_stream_pack'] ?? 6);        // “слів” за раз
            $delayUs = (int)($p->options['simulate_stream_delay_us'] ?? 40_000); // 40 мс

            $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!$parts) { $onChunk($text); return; }

            $acc = ''; $i = 0;
            foreach ($parts as $piece) {
                $acc .= $piece;
                if (++$i >= $pack) {
                    $onChunk($acc);
                    usleep($delayUs);
                    $acc = ''; $i = 0;
                }
            }
            if ($acc !== '') $onChunk($acc);
        };

        $parseLine = function (string $line) use ($emitSplit) {
            $line = trim($line);
            if ($line === '') return null;

            // Підтримка і "data: {...}", і чистого JSON рядка
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }

            if ($line === '[DONE]') return 'DONE';
            if ($line === '' || ($line[0] !== '{' && $line[0] !== '[')) return null;

            $event = json_decode($line, true);
            if (!is_array($event)) return null;

            // Дістаємо текст (може бути у content.parts[0].text або delta.text)
            $delta =
                $event['candidates'][0]['content']['parts'][0]['text']
                ?? $event['candidates'][0]['delta']['text']
                ?? '';

            $emitSplit($delta);
            return null;
        };

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') { usleep(10_000); continue; }

            $buf .= $chunk;

            // Розбір по \n (SSE/NDJSON)
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);

                $res = $parseLine($line);
                if ($res === 'DONE') { $buf = ''; break 2; }
            }
        }

        return new AiResponse(true, $full, usage: [], raw: []);
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
        $model  = $this->cfg['image_model'] ?? 'imagen-4.0-generate-001';

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
        $model = $this->cfg['embed_model'] ?? 'gemini-embedding-001';

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
        $model = $this->cfg['model'] ?? 'gemini-2.5-flash';

        // Збираємо parts:
        // - text → {text:'...'}
        // - image (url або base64) → або fileData/inline_data
        $parts = [];
        $contentParts = $p->messages[0]['content'] ?? [];

        foreach ($contentParts as $part) {
            $type = $part['type'] ?? null;

            if ($type === 'text') {
                $txt = (string)($part['text'] ?? '');
                if ($txt !== '') $parts[] = ['text' => $txt];
                continue;
            }

            if ($type === 'image_url') {
                // Стягуємо байти, кодуємо у base64 → inlineData
                $url = $part['image_url']['url'] ?? $part['url'] ?? null;
                if ($url) {
                    try {
                        $resp = \Illuminate\Support\Facades\Http::get($url)->throw();
                        $bin  = $resp->body();
                        // визнач декілька типів або задай дефолт
                        $mime = $resp->header('Content-Type') ?: ($part['mime'] ?? 'image/png');
                        $b64  = base64_encode($bin);
                        $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $b64]];
                    } catch (\Throwable $e) {
                        // ігноруємо цю картинку
                    }
                }
                continue;
            }

            if ($type === 'inline_base64') {
                // наш узагальнений тип → нормалізуємо
                $mime = $part['mime'] ?? 'image/png';
                $raw  = (string)($part['data'] ?? '');

                // зняти data:-префікс (якщо є)
                if (str_starts_with($raw, 'data:')) {
                    $raw = preg_replace('#^data:[^;]+;base64,#i', '', $raw);
                }

                // strict decode → перевірка валідності
                $bin = base64_decode($raw, true);
                if ($bin === false) {
                    // невалідний base64 — пропускаємо
                    continue;
                }

                // re-encode у стандартний base64 (без переносу)
                $b64 = base64_encode($bin);

                // додаємо у потрібному для Gemini форматі (camelCase)
                $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $b64]];
                continue;
            }
        }

// Захист від порожніх parts
        if (empty($parts)) {
            return new AiResponse(false, null, [], [], 'vision_parts_missing');
        }

        $url = rtrim($this->cfg['endpoint'], '/') . "/v1beta/models/".($this->cfg['model'] ?? 'gemini-1.5-flash').":generateContent";
        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => $p->options['temperature'] ?? 0.3,
            ],
        ];

        $res = \Illuminate\Support\Facades\Http::withHeaders(['x-goog-api-key' => $this->cfg['api_key']])
            ->acceptJson()
            ->post($url, $payload)
            ->throw()
            ->json();

        $msg = $res['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return new AiResponse((bool)$msg, $msg, ['driver'=>'gemini','model'=>$this->cfg['model'] ?? 'gemini-1.5-flash'], $res, $msg ? null : 'empty_vision_response');
    }
}
