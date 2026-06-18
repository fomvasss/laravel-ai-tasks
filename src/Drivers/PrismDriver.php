<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Cost;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Psr\Http\Message\StreamInterface;

final class PrismDriver implements AiDriver
{
    public function __construct(
        private readonly string $provider,
        private readonly array  $cfg,
    ) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text', 'embed', 'image'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], "driver_not_configured: {$this->provider}");
        }

        return match ($p->modality) {
            'embed' => $this->sendEmbed($p),
            'image' => $this->sendImage($p),
            default => $this->sendText($p),
        };
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], "driver_not_configured: {$this->provider}");
        }

        return match (true) {
            $this->provider === 'gemini'    => $this->streamGemini($p, $onChunk),
            $this->provider === 'anthropic' => $this->streamAnthropic($p, $onChunk),
            $this->isOpenAICompatible()     => $this->streamOpenAICompatible($p, $onChunk),
            default                         => $this->streamViaPrism($p, $onChunk),
        };
    }

    // ── Native SSE streams ─────────────────────────────────────────────────

    // OpenAI-compatible providers: openai, deepseek, groq, mistral, xai, openrouter, perplexity, ...
    private function isOpenAICompatible(): bool
    {
        return (bool) config("prism.providers.{$this->provider}.url")
            || $this->provider === 'openai';
    }

    private function streamOpenAICompatible(AiPayload $p, callable $onChunk): AiResponse
    {
        $model    = $p->options['model'] ?? $this->cfg['model'];
        $baseUrl  = rtrim(
            config("prism.providers.{$this->provider}.url", 'https://api.openai.com/v1'),
            '/'
        );
        $messages = $this->buildChatMessages($p);

        $resp = Http::withToken($this->cfg['api_key'])
            ->withOptions(['stream' => true])
            ->post("{$baseUrl}/chat/completions", array_filter([
                'model'          => $model,
                'messages'       => $messages,
                'stream'         => true,
                'stream_options' => ['include_usage' => true],
                'temperature'    => $p->options['temperature'] ?? 0.3,
                'max_tokens'     => $p->options['max_tokens'] ?? null,
            ]));

        $fullText  = '';
        $tokensIn  = null;
        $tokensOut = null;

        foreach ($this->sseLines($resp->getBody()) as $line) {
            if ($line === '[DONE]') break;

            $data  = json_decode($line, true);
            $delta = $data['choices'][0]['delta']['content'] ?? '';

            if ($delta !== '') {
                $fullText .= $delta;
                $onChunk($delta);
            }

            if (isset($data['usage'])) {
                $tokensIn  = $data['usage']['prompt_tokens'] ?? null;
                $tokensOut = $data['usage']['completion_tokens'] ?? null;
            }
        }

        $usage         = ['driver' => $this->provider, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $fullText, $usage);
    }

    private function streamGemini(AiPayload $p, callable $onChunk): AiResponse
    {
        $model   = $p->options['model'] ?? $this->cfg['model'];
        $baseUrl = rtrim(config('prism.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');

        $contents = array_map(fn($m) => [
            'role'  => $m instanceof AssistantMessage ? 'model' : 'user',
            'parts' => [['text' => $m->content]],
        ], $p->messages);

        $body = array_filter([
            'contents'          => $contents,
            'systemInstruction' => $p->systemPrompt
                ? ['parts' => [['text' => $p->systemPrompt]]]
                : null,
            'generationConfig'  => array_filter([
                'temperature'     => $p->options['temperature'] ?? 0.3,
                'maxOutputTokens' => $p->options['max_tokens'] ?? null,
            ]),
        ]);

        $resp = Http::withOptions(['stream' => true])
            ->withQueryParameters(['key' => $this->cfg['api_key'], 'alt' => 'sse'])
            ->post("{$baseUrl}/{$model}:streamGenerateContent", $body);

        $fullText  = '';
        $tokensIn  = null;
        $tokensOut = null;

        foreach ($this->sseLines($resp->getBody()) as $line) {
            $data  = json_decode($line, true);
            $delta = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if ($delta !== '') {
                $fullText .= $delta;
                $onChunk($delta);
            }

            if (isset($data['usageMetadata'])) {
                $tokensIn  = $data['usageMetadata']['promptTokenCount'] ?? null;
                $tokensOut = $data['usageMetadata']['candidatesTokenCount'] ?? null;
            }
        }

        $usage         = ['driver' => $this->provider, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $fullText, $usage);
    }

    private function streamAnthropic(AiPayload $p, callable $onChunk): AiResponse
    {
        $model    = $p->options['model'] ?? $this->cfg['model'];
        $version  = config('prism.providers.anthropic.version', '2023-06-01');
        $messages = array_map(fn($m) => [
            'role'    => $m instanceof AssistantMessage ? 'assistant' : 'user',
            'content' => $m->content,
        ], $p->messages);

        $body = array_filter([
            'model'      => $model,
            'messages'   => $messages,
            'system'     => $p->systemPrompt,
            'max_tokens' => $p->options['max_tokens'] ?? 4096,
            'stream'     => true,
            'temperature'=> $p->options['temperature'] ?? 0.3,
        ]);

        $resp = Http::withHeaders([
            'x-api-key'         => $this->cfg['api_key'],
            'anthropic-version' => $version,
        ])
            ->withOptions(['stream' => true])
            ->post('https://api.anthropic.com/v1/messages', $body);

        $fullText       = '';
        $tokensIn       = null;
        $tokensOut      = null;
        $cacheReadTok   = null;
        $cacheWriteTok  = null;

        foreach ($this->sseLines($resp->getBody()) as $line) {
            $data  = json_decode($line, true);
            $type  = $data['type'] ?? '';

            if ($type === 'content_block_delta') {
                $delta = $data['delta']['text'] ?? '';
                if ($delta !== '') {
                    $fullText .= $delta;
                    $onChunk($delta);
                }
            }

            if ($type === 'message_start' && isset($data['message']['usage'])) {
                $tokensIn      = $data['message']['usage']['input_tokens'] ?? null;
                $cacheReadTok  = $data['message']['usage']['cache_read_input_tokens'] ?? null;
                $cacheWriteTok = $data['message']['usage']['cache_creation_input_tokens'] ?? null;
            }

            if ($type === 'message_delta' && isset($data['usage'])) {
                $tokensOut = $data['usage']['output_tokens'] ?? null;
            }
        }

        $usage = [
            'driver'             => $this->provider,
            'tokens_in'          => $tokensIn,
            'tokens_out'         => $tokensOut,
            'cache_read_tokens'  => $cacheReadTok,
            'cache_write_tokens' => $cacheWriteTok,
        ];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $fullText, $usage);
    }

    // ── Prism stream (with asText fallback) ───────────────────────────────

    private function streamViaPrism(AiPayload $p, callable $onChunk): AiResponse
    {
        $model = $p->options['model'] ?? $this->cfg['model'];

        $prism = Prism::text()
            ->using($this->provider, $model)
            ->withMessages($p->messages)
            ->usingTemperature($p->options['temperature'] ?? 0.3);

        if ($p->systemPrompt) {
            $prism = $prism->withSystemPrompt($p->systemPrompt);
        }

        try {
            $fullText = '';
            foreach ($prism->asStream() as $chunk) {
                $delta = $chunk->text ?? '';
                if ($delta !== '') {
                    $fullText .= $delta;
                    $onChunk($delta);
                }
            }
            return new AiResponse(true, $fullText, ['driver' => $this->provider]);
        } catch (\Throwable) {
            $result        = $prism->asText();
            $usage         = $this->mapUsage($result->usage);
            $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);
            $onChunk($result->text);
            return new AiResponse(true, $result->text, $usage);
        }
    }

    // ── SSE helper ────────────────────────────────────────────────────────

    /** @return \Generator<string> yields data payloads (without "data: " prefix) */
    private function sseLines(StreamInterface $body): \Generator
    {
        $buf = '';
        while (!$body->eof()) {
            $byte = $body->read(1);
            if ($byte === "\n") {
                $line = rtrim($buf, "\r");
                $buf  = '';
                if (str_starts_with($line, 'data: ')) {
                    yield substr($line, 6);
                }
            } else {
                $buf .= $byte;
            }
        }
    }

    // ── Text / Embed ──────────────────────────────────────────────────────

    private function sendText(AiPayload $p): AiResponse
    {
        $model = $p->options['model'] ?? $this->cfg['model'];

        $prism = Prism::text()
            ->using($this->provider, $model)
            ->withMessages($p->messages)
            ->usingTemperature($p->options['temperature'] ?? 0.3);

        if ($p->systemPrompt) {
            $prism = $prism->withSystemPrompt($p->systemPrompt);
        }

        if (!empty($p->options['tools'])) {
            $prism = $prism->withTools($p->options['tools']);
        }

        if (!empty($p->options['schema'])) {
            $prism = $prism->asStructuredOutput($p->options['schema']);
        }

        if (!empty($p->options['max_tokens'])) {
            $prism = $prism->withMaxTokens((int) $p->options['max_tokens']);
        }

        $result        = $prism->asText();
        $usage         = $this->mapUsage($result->usage);
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        $toolCalls = [];
        foreach ($result->toolCalls ?? [] as $tc) {
            $toolCalls[] = ['id' => $tc->id ?? null, 'name' => $tc->name ?? null, 'arguments' => $tc->arguments ?? []];
        }

        return new AiResponse(ok: true, content: $result->text, usage: $usage, raw: [], toolCalls: $toolCalls);
    }

    private function sendImage(AiPayload $p): AiResponse
    {
        $baseUrl = rtrim(config('prism.providers.openai.url', 'https://api.openai.com/v1'), '/');
        $model   = $p->options['model'] ?? $this->cfg['image_model'] ?? 'dall-e-3';
        $prompt  = $this->extractPrompt($p);

        $body = array_filter([
            'model'   => $model,
            'prompt'  => $prompt,
            'n'       => $p->options['n'] ?? 1,
            'size'    => $p->options['size'] ?? '1024x1024',
            'quality' => $p->options['quality'] ?? null,
            'style'   => $p->options['style'] ?? null,
        ]);

        $resp = Http::withToken($this->cfg['api_key'])
            ->post("{$baseUrl}/images/generations", $body);

        if (! $resp->successful()) {
            $error = $resp->json('error.message') ?? $resp->body();
            return new AiResponse(false, null, ['driver' => $this->provider], [], $error);
        }

        $item    = $resp->json('data.0') ?? [];
        $content = $item['url'] ?? $item['b64_json'] ?? null;

        $usage         = ['driver' => $this->provider, 'tokens_in' => null, 'tokens_out' => null];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $content, $usage);
    }

    private function sendEmbed(AiPayload $p): AiResponse
    {
        $model = $p->options['embed_model'] ?? $this->cfg['embed_model'] ?? null;
        $input = $p->messages[0] ?? null;

        if ($input === null) {
            return new AiResponse(false, null, [], [], 'embed_input_missing');
        }

        $text   = $input instanceof UserMessage ? $input->content : (string) $input;
        $result = Prism::embeddings()->using($this->provider, $model)->fromInput($text)->asEmbeddings();

        $usage         = ['driver' => $this->provider, 'tokens_in' => $result->usage->tokens ?? null];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(ok: true, content: json_encode($result->embeddings[0]->embedding ?? []), usage: $usage);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function extractPrompt(AiPayload $p): string
    {
        $first = $p->messages[0] ?? null;

        if ($first === null) return '';
        if (is_array($first))  return (string) ($first['content'] ?? '');

        return $first->content ?? '';
    }

    private function buildChatMessages(AiPayload $p): array
    {
        $messages = [];

        if ($p->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $p->systemPrompt];
        }

        foreach ($p->messages as $m) {
            $messages[] = [
                'role'    => $m instanceof AssistantMessage ? 'assistant' : 'user',
                'content' => $m->content,
            ];
        }

        return $messages;
    }

    private function mapUsage(?object $usage): array
    {
        if ($usage === null) {
            return ['driver' => $this->provider];
        }

        return [
            'driver'             => $this->provider,
            'tokens_in'          => $usage->promptTokens ?? null,
            'tokens_out'         => $usage->completionTokens ?? null,
            'cache_read_tokens'  => $usage->cacheReadInputTokens ?? null,
            'cache_write_tokens' => $usage->cacheWriteInputTokens ?? null,
        ];
    }
}
