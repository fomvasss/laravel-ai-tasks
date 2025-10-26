<?php

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Illuminate\Support\Facades\Http;

final class OpenAiDriver implements AiDriver
{
    public function __construct(private array $cfg)
    {
    }

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text', 'chat', 'image', 'vision', 'embed'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], 'driver_not_configured: openai');
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

//    public function queue(AiPayload $p, AiContext $c, ?string $queue = null): string
//    {
//        return dispatch(
//            (new \Fomvasss\AiTasks\Jobs\ProcessAiPayload('openai', $p, $c))
//                ->onQueue($queue ?? config('ai.queues.default'))
//        )->id;
//    }

    /**
     * TODO: check this
     *
     * @param AiPayload $p
     * @param AiContext $c
     * @param callable $onChunk
     * @return AiResponse
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], 'driver_not_configured: openai');
        }

        $body = [
            'model' => $this->cfg['model'],
            'messages' => $p->messages,
            'temperature' => $p->options['temperature'] ?? 0.3,
            'stream' => true,
        ];

        if ($p->schema) $body['response_format'] = ['type' => 'json_object'];
        if (!empty($p->options['tools'])) {
            $body['tools'] = $p->options['tools'];
            if (isset($p->options['tool_choice'])) $body['tool_choice'] = $p->options['tool_choice'];
        }

        $accum = '';
        $toolCalls = [];

        Http::withToken($this->cfg['api_key'])
            ->withOptions(['stream' => true])
            ->post(rtrim($this->cfg['endpoint'], '/') . '/chat/completions', $body)
            ->throw()
            ->sink(function ($chunk) use (&$accum, &$toolCalls, $onChunk) {
                // OpenAI stream = SSE with strings "data: {json}" і "data: [DONE]"
                foreach (preg_split("/\r\n|\n|\r/", $chunk) as $line) {
                    $line = trim($line);
                    if ($line === '' || !str_starts_with($line, 'data:')) continue;
                    $data = substr($line, 5);
                    $data = trim($data);

                    if ($data === '[DONE]') {
                        $onChunk(['type' => 'done']);
                        continue;
                    }

                    $json = json_decode($data, true);
                    if (!is_array($json)) continue;

                    $delta = $json['choices'][0]['delta'] ?? [];
                    // text chunks
                    if (isset($delta['content'])) {
                        $accum .= $delta['content'];
                        $onChunk(['type' => 'text', 'delta' => $delta['content']]);
                    }
                    // tool_calls chunks
                    if (!empty($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            $name = $tc['function']['name'] ?? null;
                            $args = $tc['function']['arguments'] ?? '';
                            // can be aggregated by index/ID
                            $toolCalls[$idx]['name'] = $name ?? ($toolCalls[$idx]['name'] ?? null);
                            $toolCalls[$idx]['arguments'] = ($toolCalls[$idx]['arguments'] ?? '') . $args;
                            $onChunk(['type' => 'tool_call_delta', 'index' => $idx, 'name' => $name, 'arguments_delta' => $args]);
                        }
                    }
                }
            });

        return new AiResponse(true, $accum, ['driver' => 'openai'], raw: [], error: null, toolCalls: array_values($toolCalls));
    }

    protected function sendTextAndChat(AiPayload $p, AiContext $c): AiResponse
    {
        $body = [
            'model' => $this->cfg['model'],
            'messages' => $p->messages,
            'temperature' => $p->options['temperature'] ?? 0.3,
        ];

        if ($p->schema) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if (!empty($p->options['tools'])) {
            $body['tools'] = $p->options['tools'];          // format OpenAI!
            if (isset($p->options['tool_choice'])) {
                $body['tool_choice'] = $p->options['tool_choice']; // 'auto'|'none'|['type'=>'function','function'=>['name'=>'...']]
            }
        }

        // chat/text приклад
        $res = Http::withToken($this->cfg['api_key'])
            ->acceptJson()
            ->post(rtrim($this->cfg['endpoint'], '/') . '/chat/completions', $body)
            ->throw()
            ->json();

        $choice = $res['choices'][0]['message'] ?? [];
        $msg = $choice['content'] ?? null;

        $toolCalls = [];
        if (!empty($choice['tool_calls'])) {
            foreach ($choice['tool_calls'] as $tc) {
                $toolCalls[] = [
                    'id' => $tc['id'] ?? null,
                    'name' => $tc['function']['name'] ?? null,
                    'arguments' => $tc['function']['arguments'] ?? '{}', // JSON string
                ];
            }
        }

        $usage = $res['usage'] ?? [];
        $usage['driver'] = 'openai';

        $usage = $res['usage'] ?? [];
        $usage['driver'] = 'openai';

        return new AiResponse(true, $msg, $usage, $res, null, $toolCalls);
    }


    protected function sendImage(AiPayload $p, AiContext $c): AiResponse
    {
        $prompt = $p->messages[0]['content'] ?? '';
        $size = $p->options['size'] ?? '1024x1024';
        $n = (int)($p->options['n'] ?? 1);
        $model = $this->cfg['image_model'] ?? 'gpt-image-1';

        // те, що хоче користувач (url|b64_json), за замовч. b64_json
        $wantedFormat = $p->options['response_format'] ?? 'b64_json';

        // allowlist для response_format (у gpt-image-1 воно часто не підтримується)
        $modelSupportsRespFormat = in_array(strtolower($model), ['dall-e-3', 'dall-e-2'], true);

        // конструюємо тіло запиту
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'n' => $n,
        ];
        if ($modelSupportsRespFormat && in_array($wantedFormat, ['b64_json', 'url'], true)) {
            $body['response_format'] = $wantedFormat;
        }

        // функція-виконавець (для ретраю без response_format)
        $doRequest = function (array $payload) {
            return Http::withToken($this->cfg['api_key'])
                ->acceptJson()
                ->post(rtrim($this->cfg['endpoint'], '/') . '/images/generations', $payload)
                ->throw(function ($resp, $e) {
                    // Don't throw it away right away — let's give the retry a chance above
                })
                ->json();
        };

        $res = $doRequest($body);

        // якщо 400 через невідомий параметр — прибрати і повторити
        if (
            isset($res['error']['message']) &&
            stripos($res['error']['message'], "Unknown parameter: 'response_format'") !== false
        ) {
            unset($body['response_format']);
            $res = $doRequest($body);
        }

        // розбір відповіді
        $first = null;
        $effectiveFormat = $body['response_format'] ?? 'b64_json'; // якщо прибрали — вважай b64_json за дефолт
        if (isset($res['data'][0])) {
            $first = $effectiveFormat === 'url'
                ? ($res['data'][0]['url'] ?? null)
                : ($res['data'][0]['b64_json'] ?? null);
        }

        return new AiResponse(
            ok: (bool)$first,
            content: $first,
            usage: ['driver' => 'openai', 'images' => $n, 'response_format' => $effectiveFormat, 'model' => $model],
            raw: $res,
            error: $first ? null : ($res['error']['message'] ?? 'empty_image_response')
        );
    }

    protected function sendEmbed(AiPayload $p, AiContext $c): AiResponse
    {
        $model = $this->cfg['embed_model'] ?? 'text-embedding-3-small';
        // input: рядок або масив рядків
        $input = $p->messages[0]['content'] ?? ($p->options['input'] ?? null);
        if ($input === null) {
            return new AiResponse(false, null, [], [], 'embed_input_missing');
        }

        $res = Http::withToken($this->cfg['api_key'])
            ->acceptJson()
            ->post(rtrim($this->cfg['endpoint'], '/') . '/embeddings', [
                'model' => $model,
                'input' => $input,
            ])->throw()->json();

        // Відповідь: data[*].embedding
        $vectors = array_map(fn($d) => $d['embedding'] ?? [], $res['data'] ?? []);
        // Якщо був один рядок — повернемо один вектор, інакше масив
        $content = count($vectors) === 1 ? $vectors[0] : $vectors;

        return new AiResponse(true, json_encode($content), ['driver' => 'openai', 'model' => $model], $res);
    }

    protected function sendVision(AiPayload $p, AiContext $c): AiResponse
    {
        $model = $this->cfg['model'] ?? 'gpt-4.1-mini';

        // беремо перше повідомлення
        $msg = $p->messages[0] ?? ['role' => 'user', 'content' => []];
        $parts = $msg['content'] ?? [];

        $normalized = [];
        foreach ($parts as $part) {
            $type = $part['type'] ?? null;

            if ($type === 'text') {
                $txt = (string)($part['text'] ?? '');
                if ($txt !== '') $normalized[] = ['type' => 'text', 'text' => $txt];
            } elseif ($type === 'image_url') {
                // підтримуємо як ['image_url'=>['url'=>...]] так і кастомні 'url'/'data'
                $url = $part['image_url']['url'] ?? $part['url'] ?? $part['data'] ?? null;
                if ($url) {
                    $image = ['url' => $url];
                    if (!empty($part['detail'])) $image['detail'] = $part['detail']; // optional: 'auto'|'low'|'high'
                    $normalized[] = ['type' => 'image_url', 'image_url' => $image];
                }
            } elseif ($type === 'inline_base64') {
                // наш загальний тип → перетворюємо у data-uri
                $mime = $part['mime'] ?? 'image/png';
                $b64 = $part['data'] ?? '';
                if ($b64 !== '') {
                    $normalized[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$b64}"]];
                }
            }
            // інші типи ігноруємо
        }

        if (empty($normalized)) {
            return new AiResponse(false, null, [], [], 'vision_parts_missing');
        }

        $res = Http::withToken($this->cfg['api_key'])
            ->acceptJson()
            ->post(rtrim($this->cfg['endpoint'], '/') . '/chat/completions', [
                'model' => $model,
                'messages' => [['role' => $msg['role'] ?? 'user', 'content' => $normalized]],
                'temperature' => $p->options['temperature'] ?? 0.3,
            ])->throw()->json();

        $msgTxt = $res['choices'][0]['message']['content'] ?? null;
        $usage = ($res['usage'] ?? []) + ['driver' => 'openai', 'model' => $model];

        return new AiResponse((bool)$msgTxt, $msgTxt, $usage, $res, $msgTxt ? null : 'empty_vision_response');
    }
}
