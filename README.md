# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

## Support

If this package is useful to you, consider supporting its development:

[![Monobank](https://img.shields.io/badge/Donate-Monobank-black)](https://send.monobank.ua/jar/5xsqtHvVrY)
[![Ko-Fi](https://img.shields.io/badge/Donate-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/fomvasss)
[![USDT TRC20](https://img.shields.io/badge/Donate-USDT%20TRC20-26A17B?logo=tether&logoColor=white)](https://link.trustwallet.com/send?coin=195&address=THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf&token_id=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t)

> USDT TRC20: `THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf`

AI task orchestrator for Laravel. Handles routing, queuing, audit logging, budget tracking, and webhook processing on top of [laravel/ai](https://laravel.com/docs/ai-sdk) as the transport layer.

[Українська документація](README.uk.md)

## Dashboard

Built-in web UI at `/ai-tasks` — runs list with stats, filters, and per-run detail (request, response, tokens, cost).

![Dashboard](art/dashboard.gif)

Configurable via `config/ai-tasks.php`:

```php
'dashboard' => [
    'enabled'       => env('AI_DASHBOARD_ENABLED', true),
    'path'          => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware'    => ['web'],
    'poll_interval' => env('AI_DASHBOARD_POLL', 3),       // seconds; 0 = off
    'theme'         => env('AI_DASHBOARD_THEME', 'system'), // light|dark|system
    'per_page'      => env('AI_DASHBOARD_PER_PAGE', 50),
],
```

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13
- [laravel/ai](https://laravel.com/docs/ai-sdk) ^0.8

## Installation

```bash
composer require fomvasss/laravel-ai-tasks
```

Publish configs and run migrations:

```bash
# laravel/ai provider config (credentials go here)
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config

# this package config (routing, budgets, queues)
php artisan vendor:publish --tag=ai-tasks-config

php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

Add API keys to `.env` — credentials are read by `laravel/ai`:

```env
AI_DEFAULT=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
DEEPSEEK_API_KEY=sk-...
GROQ_API_KEY=gsk_...
```

### Two config files

| File | Purpose |
|---|---|
| `config/ai.php` | laravel/ai — API keys, provider URLs |
| `config/ai-tasks.php` | this package — models, prices, routing, budgets |

## Horizon / Queue

Two queues are used by default:

```env
AI_QUEUE=ai
AI_QUEUE_POST=ai-post
```

Example Horizon config:

```php
'supervisor-ai' => [
    'connection'   => 'redis',
    'queue'        => ['ai'],
    'balance'      => 'auto',
    'minProcesses' => 2,
    'maxProcesses' => 20,
    'tries'        => 3,
    'timeout'      => 300,
],
'supervisor-ai-post' => [
    'connection'   => 'redis',
    'queue'        => ['ai-post'],
    'balance'      => 'simple',
    'minProcesses' => 1,
    'maxProcesses' => 8,
    'tries'        => 3,
    'timeout'      => 60,
],
```

## Creating a Task

```bash
php artisan ai:make-task SummarizeTask
php artisan ai:make-task Orders/AnalyzeTask --queued
```

```php
<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;

class SummarizeTask extends AiTask
{
    public function __construct(
        private readonly string $text,
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage("Summarize: {$this->text}")],
            systemPrompt: 'You are a concise summarizer. Reply in 3 sentences max.',
            options: ['temperature' => 0.3],
        );
    }

    public function postprocess(AiResponse $response): AiResponse|array
    {
        // save to DB, dispatch further jobs, etc.
        return $response;
    }
}
```

## Running Tasks

```php
use Fomvasss\AiTasks\Facades\AI;

// Sync
$response = AI::send(new SummarizeTask($text));
echo $response->content;

// Async (queue)
$runId = AI::queue(new SummarizeTask($text));

// Streaming
$response = AI::stream(new SummarizeTask($text), function (string $chunk) {
    echo $chunk;
});
// $response->content — full accumulated text
// $response->usage  — tokens + cost (same as AI::send)

// Override driver at runtime
$response = AI::send(new SummarizeTask($text), drivers: 'anthropic');
```

## Streaming

`AI::stream()` delivers response text chunk by chunk via a callback, useful for real-time UI (SSE, WebSockets).

```php
$response = AI::stream(
    new SummarizeTask($text),
    function (string $chunk) {
        echo $chunk;          // or: event('stream', $chunk)
    },
    drivers: ['openai'],      // optional driver override
);

// After the stream ends:
$response->content;           // full accumulated text
$response->usage;             // tokens + cost
```

### Provider support

All providers supported by `laravel/ai` work with streaming automatically — OpenAI, Anthropic, Gemini, DeepSeek, Groq, Mistral, xAI, Ollama, and any OpenAI-compatible endpoint.

### Long responses

`AI::send()` has a default 60-second timeout per request. For tasks that generate large outputs (long articles, stories, detailed reports), use `AI::stream()` — it has no timeout by default:

```php
$response = AI::stream(new WriteStoryTask(), function (string $chunk) {
    // process chunks, or ignore them
}, drivers: ['deepseek']);

$response->content; // full accumulated text
```

## Tools

Override `tools()` on any task to pass `Laravel\Ai\Contracts\Tool[]` to the underlying `AnonymousAgent`. Tools are forwarded automatically on `send()`, `stream()`, and `queue()`.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ResearchTask extends AiTask
{
    public function tools(): array
    {
        return [
            new class implements Tool {
                public function name(): string        { return 'web_search'; }
                public function description(): string { return 'Search the web for current information.'; }

                public function handle(Request $request): string
                {
                    $query = $request['query'] ?? '';
                    // call your search API here
                    return json_encode(['results' => ["Result for: {$query}"]]);
                }

                public function schema(JsonSchema $schema): array
                {
                    return ['query' => $schema->string('The search query')];
                }
            },
        ];
    }

    public function modality(): string { return 'text'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage('What happened in tech this week?')],
        );
    }
}
```

**Note:** anonymous classes implementing `Tool` must define `name()` — without it the tool name resolver falls back to `class_basename()`, which produces an invalid identifier for OpenAI.

The agent decides when and how to invoke tools. Each tool call is executed locally and the result is returned to the model for the next step.

## MCP Tools

Connect to any remote MCP server (Streamable HTTP transport, JSON-RPC 2.0) without installing `laravel/mcp`. Implement a thin HTTP client and wrap each discovered tool:

```php
// app/Ai/Mcp/HttpMcpClient.php
class HttpMcpClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
    ) {}

    public function listTools(): array
    {
        return $this->rpc('tools/list')['tools'] ?? [];
    }

    public function readResource(string $uri): string
    {
        $result = $this->rpc('resources/read', ['uri' => $uri]);
        return collect($result['contents'] ?? [])
            ->map(fn($c) => $c['text'] ?? '')
            ->filter()
            ->implode("\n");
    }

    public function callTool(string $name, array $arguments = []): string
    {
        $result  = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        $content = $result['content'] ?? [];
        $isError = $result['isError'] ?? false;
        $text = collect($content)
            ->filter(fn($c) => ($c['type'] ?? '') === 'text')
            ->map(fn($c) => $c['text'] ?? '')
            ->implode("\n");
        if ($isError) {
            throw new \RuntimeException("MCP tool error [{$name}]: {$text}");
        }
        return $text ?: json_encode($result);
    }

    private function rpc(string $method, array $params = []): array
    {
        static $id = 0;
        $response = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->post($this->url, ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => $params]);
        $data = $response->json();
        if (isset($data['error'])) {
            throw new \RuntimeException("MCP error [{$method}]: " . ($data['error']['message'] ?? ''));
        }
        return $data['result'] ?? [];
    }
}
```

```php
// app/Ai/Mcp/HttpMcpTool.php
class HttpMcpTool implements Tool
{
    public function __construct(
        private readonly HttpMcpClient $client,
        private readonly string $name,
        private readonly string $toolDescription,
        private readonly array $inputSchema,
    ) {}

    public function name(): string        { return $this->name; }
    public function description(): string { return $this->toolDescription; }

    public function handle(Request $request): string
    {
        return $this->client->callTool($this->name, $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        if (empty($this->inputSchema)) return [];
        try {
            $type = \Illuminate\JsonSchema\JsonSchema::fromArray(
                \Laravel\Ai\Schema\SchemaNormalizer::normalize($this->inputSchema)
            );
        } catch (\Throwable) {
            return [];
        }
        return $type instanceof \Illuminate\JsonSchema\Types\ObjectType
            ? (fn(): array => $this->properties)->call($type)
            : [];
    }
}
```

```php
// Task that discovers and uses all tools from a remote MCP server
class CrmTask extends AiTask
{
    private ?HttpMcpClient $mcpClient = null;

    public function __construct(private readonly string $question) {}

    public function tools(): array
    {
        $client = $this->client();
        return collect($client->listTools())
            ->map(fn(array $t) => new HttpMcpTool(
                client:          $client,
                name:            $t['name'],
                toolDescription: $t['description'] ?? $t['name'],
                inputSchema:     $t['inputSchema'] ?? [],
            ))
            ->all();
    }

    public function toPayload(): AiPayload
    {
        $me = $this->client()->readResource('crm://me');
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage($this->question)],
            systemPrompt: "Current user: {$me}\nUse provided tools to answer.",
        );
    }

    private function client(): HttpMcpClient
    {
        return $this->mcpClient ??= new HttpMcpClient(
            url:   config('services.crm_mcp.url'),
            token: config('services.crm_mcp.token'),
        );
    }

    public function modality(): string      { return 'text'; }
    public function serializeForQueue(): array { return [$this->question]; }
}
```

```php
AI::send(new CrmTask('Show workload for all users'));
AI::queue(new CrmTask('Create a task "Fix login bug" in project CRM, priority 3'));
```

## Job Timeout

Override `jobTimeout()` on any task to control how long the queue job is allowed to run before Horizon kills it:

```php
class HeavyAnalysisTask extends AiTask
{
    // default is 300 seconds; raise for long multi-step tool chains
    public function jobTimeout(): int { return 600; }
}
```

The value is passed to `ProcessAiPayload` at dispatch time. Make sure the Horizon supervisor `timeout` is at least as large as your highest `jobTimeout()`.

## Driver Routing

Tasks are routed to drivers via `config/ai-tasks.php`:

```php
'routing' => [
    'summarize'       => ['openai', 'anthropic'], // fallback chain
    'orders_analyze'  => ['gemini'],
],
```

Or on the task instance:

```php
AI::send((new SummarizeTask($text))->viaDrivers('gemini'));
```

## Multi-tenant Budget Tracking

```php
// config/ai-tasks.php
'budgets' => [
    'tenant-abc' => ['monthly_usd' => 50.0],
    'default'    => ['monthly_usd' => 100.0],
],
```

The `TenantResolver` picks up tenant ID from `X-Tenant-Id` header, authenticated user, or config default. Override it by binding your own resolver in a service provider:

```php
$this->app->scoped(\Fomvasss\AiTasks\Support\TenantResolver::class, fn() => new MyTenantResolver());
```

## Cost Tracking

Set pricing per driver in `config/ai-tasks.php` (per 1M tokens):

```php
'anthropic' => [
    'model' => 'claude-sonnet-4-6',
    'price' => [
        'in'          => 3.00,
        'out'         => 15.00,
        'cache_write' => 3.75,  // prompt caching write
        'cache_read'  => 0.30,  // prompt caching read
    ],
],
```

Cost is calculated after each response and stored in `ai_runs.cost`. If `price` is not set, `cost` is `null` but token counts are always saved.

Query spend per tenant:

```php
AiRun::where('tenant_id', $tenantId)
    ->where('status', 'ok')
    ->sum('cost'); // fast SQL, indexed column
```

## Prompt Caching (Anthropic)

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($prompt)],
    systemPrompt: $longSystemPrompt,
    options: ['cache' => true], // caches systemPrompt on Anthropic
);
```

## Queued Tasks

Implement `ShouldQueueAi` and define `serializeForQueue()`:

```php
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;

class AnalyzeTask extends AiTask implements ShouldQueueAi
{
    public function __construct(private readonly int $productId) {}

    public function serializeForQueue(): array
    {
        return [$this->productId];
    }

    public function viaQueues(): array
    {
        return ['request' => 'ai', 'post' => 'ai-post'];
    }
}
```

### Delayed dispatch

Pass a `delay` to `AI::queue()` to defer execution:

```php
AI::queue(new SummarizeTask($text), delay: 300);                 // 5 minutes (seconds)
AI::queue(new SummarizeTask($text), delay: now()->addHours(2));  // Carbon
AI::queue(new SummarizeTask($text), delay: new \DateInterval('PT10M'));
```

### Pre-execution guard — `shouldRun()`

Override `shouldRun()` on any task to perform a last-moment check inside the queue job, **before** the API call is made. If it returns `false`, the run is marked `skipped` and no tokens are consumed:

```php
class AnalyzeProductTask extends AiTask
{
    public function __construct(private readonly int $productId) {}

    public function shouldRun(): bool
    {
        // re-check at job execution time — the model state may have changed
        return Product::find($this->productId)?->needs_analysis ?? false;
    }
}
```

Useful when a queued task may become irrelevant by the time a worker picks it up (e.g. record deleted, status changed, result already computed).

### Idempotency

Every run is protected against duplicates via a unique `idempotency_key` stored in `ai_runs`. The default key is a hash of `[tenantId, taskName, modality, serializeForQueue()]` — so tasks with different input parameters produce different keys automatically.

Override `idempotencyKey()` when you need custom deduplication logic:

```php
class ChatTask extends AiTask
{
    public function __construct(
        private readonly string $question,
        private readonly string $messageId, // unique per message from the chat system
        private readonly array  $history = [],
    ) {}

    public function serializeForQueue(): array
    {
        return [$this->question, $this->messageId, $this->history];
    }
    // idempotencyKey() default is sufficient — messageId makes each turn unique
}
```

For chat/assistant integrations where the same question can be asked multiple times: as long as the conversation history (or a `messageId`) is part of `serializeForQueue()`, each turn produces a different key and idempotency works correctly — it only blocks genuine technical duplicates (double-send, queue retry).

## Laravel Octane

No configuration needed. The package handles Octane automatically:

- `TenantResolver` is bound as `scoped` — new instance per request/job
- `AiManager` driver cache is flushed on every `RequestReceived` and `TaskReceived` Octane event

If you provide a custom `TenantResolver` that holds per-request state, the `scoped` binding ensures it is reset correctly between requests.

## Testing

Use `AI::fake()` in tests to avoid real API calls. The fake records all calls and provides assertion helpers.

```php
use Fomvasss\AiTasks\Facades\AI;

// Default: all tasks return "fake ai response"
$fake = AI::fake();

// Fixed response for all tasks
$fake = AI::fake('Short summary.');

// Per-task responses (matched by task name)
$fake = AI::fake([
    'summarize' => 'This is a summary.',
    'translate'  => 'Це переклад.',
    '*'          => 'Default fallback.',   // catch-all
]);
```

### Assertions

```php
$fake->assertSent(SummarizeTask::class);

$fake->assertSent(SummarizeTask::class, function (AiTask $task, string $method) {
    return $task->name() === 'summarize' && $method === 'send';
});

$fake->assertNotSent(TranslateTask::class);

$fake->assertQueued(SummarizeTask::class);

$fake->assertSentCount(3);   // total calls (send + stream + queue)

$fake->assertNothingSent();
```

`AI::stream()` with fake still calls the `$onChunk` callback once with the full response, so streaming logic can be tested too.

## Events

| Event | When |
|---|---|
| `AiTaskQueued` | Task dispatched to queue |
| `AiTaskStarted` | API call begins |
| `AiTaskCompleted` | Postprocess done, response ready |
| `AiTaskFailed` | All drivers failed |
| `AiRunFinished` | Low-level: single driver call succeeded |
| `AiRunFailed` | Low-level: single driver call failed |

```php
Event::listen(AiTaskCompleted::class, function (AiTaskCompleted $event) {
    // $event->task, $event->response, $event->run
});
```

## Image Generation

Set `modality: 'image'` in the payload. Supported via OpenAI (`gpt-image-1`, `dall-e-3`) and Gemini.

```php
class GenerateImageTask extends AiTask
{
    public function modality(): string { return 'image'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'image',
            messages: [new UserMessage('A minimalist blue logo for a tech startup')],
            options: [
                'model'   => 'gpt-image-1',
                'size'    => '1024x1024', // or '3:2' landscape / '2:3' portrait
                'quality' => 'standard',
                'timeout' => 120,
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — base64 string (image/png)
        // Save to file:
        if ($resp->ok) {
            $path = storage_path('app/images/generated_' . time() . '.png');
            file_put_contents($path, base64_decode($resp->content));
        }
        return $resp;
    }
}

$r = AI::send(new GenerateImageTask(), drivers: ['openai']);
// $r->content — base64 PNG image (can be decoded and saved)
```

## Embeddings

Convert text to vector embeddings for semantic search, clustering, etc.

```php
class EmbedDocumentTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string { return 'embed'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'embed',
            messages: [$this->text], // string or array or UserMessage
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — JSON array of floats (embedding vector)
        $vector = json_decode($resp->content, true);
        return [
            'ok'      => $resp->ok,
            'dims'    => count($vector),
            'vector'  => $vector,
            'tokens'  => $resp->usage['tokens_in'] ?? null,
        ];
    }
}

$r = AI::send(new EmbedDocumentTask('Your text here'), drivers: ['openai']);
// Returns: { "ok": true, "dims": 1536, "vector": [0.023, -0.012, ...] }
```

Supported embedding models:
- OpenAI: `text-embedding-3-small`, `text-embedding-3-large`
- Gemini: `gemini-embedding-001`

## Audio & Text-to-Speech

Generate speech from text via OpenAI or ElevenLabs.

```php
class GenerateSpeechTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string { return 'audio'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'audio',
            messages: [$this->text],
            options: [
                'model'    => 'tts-1', // or 'tts-1-hd'
                'voice'    => 'alloy', // alloy, echo, fable, onyx, nova, shimmer
                'female'   => false,   // or true for ElevenLabs
                'instructions' => 'Speak clearly and slowly', // optional
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — base64 audio (MP3 or WAV)
        if ($resp->ok) {
            $path = storage_path('app/audio/speech_' . time() . '.mp3');
            file_put_contents($path, base64_decode($resp->content));
        }
        return ['ok' => $resp->ok, 'size' => strlen($resp->content)];
    }
}

AI::send(new GenerateSpeechTask('Hello world'), drivers: ['openai']);
```

## Transcription & Speech-to-Text

Convert audio files to text via OpenAI, ElevenLabs, Mistral, or Gemini.

```php
class TranscribeAudioTask extends AiTask
{
    public function __construct(private readonly string $audioPath) {}

    public function modality(): string { return 'transcription'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'transcription',
            options: [
                'path'    => $this->audioPath, // full file path
                // or use:
                // 'storage' => 'file_path', // from storage disk
                // 'disk'    => 'local',      // storage disk name
                'diarize' => true, // speaker identification (OpenAI only)
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        return [
            'ok'   => $resp->ok,
            'text' => $resp->content,
            'duration_seconds' => round(strlen($resp->content) / 100), // rough estimate
        ];
    }
}

$r = AI::send(new TranscribeAudioTask('/path/to/audio.mp3'), drivers: ['openai']);
// Returns: { "ok": true, "text": "transcribed text...", "duration_seconds": 42 }
```

Supported formats: MP3, MP4, MPEG, MPGA, M4A, OGG, WAV, WEBM

## Artisan Commands

| Command | Description |
|---|---|
| `ai:make-task Name` | Generate a task class |
| `ai:models [driver]` | List available models from provider API |
| `ai:request "prompt"` | Ad-hoc sync or queued request |
| `ai:runs` | List recent ai_runs |
| `ai:budget {tenant}` | Show monthly spend vs limit |
| `ai:retry` | List failed runs for retry |

### ai:models

```bash
# all configured drivers
php artisan ai:models

# specific driver
php artisan ai:models gemini

# filter by substring
php artisan ai:models openai --filter=gpt-4

# show token limits, release date, capabilities
php artisan ai:models anthropic --detail
```

Currently configured model is highlighted with `✓`. Providers with a URL in `ai.providers.*.url` (Groq, Mistral, DeepSeek, xAI, Ollama, OpenRouter…) are queried via the OpenAI-compatible `/v1/models` endpoint automatically.

## Supported Providers

Any provider supported by [laravel/ai](https://laravel.com/docs/ai-sdk) works automatically — just add a section to `config/ai.php` (credentials) and `config/ai-tasks.php` (model, price). No code changes needed.

The following providers are pre-configured in `config/ai-tasks.php` (just add the `.env` key):

| Provider | Driver key | Pre-configured |
|---|---|---|
| OpenAI | `openai` | ✅ |
| Anthropic | `anthropic` | ✅ |
| Google Gemini | `gemini` | ✅ |
| DeepSeek | `deepseek` | ✅ |
| Groq | `groq` | ✅ |
| Mistral | `mistral` | ✅ |
| xAI (Grok) | `xai` | ✅ |
| Ollama (local) | `ollama` | ✅ |
| VoyageAI | `voyageai` | add manually |
| AWS Bedrock | `bedrock` | add manually |
| OpenRouter | `openrouter` | add manually |
| Perplexity | `perplexity` | add manually |
| ElevenLabs | `eleven` | ✅ (audio/tts) |
| any laravel/ai provider | — | add manually |

### How credentials work

`laravel/ai` reads API keys from `config/ai.php` (published via `vendor:publish --provider="Laravel\Ai\AiServiceProvider"`). The `api_key` is **not** stored in `config/ai-tasks.php` — that file only contains model names, pricing, and routing config.

To check what `.env` key each provider expects, see:

```
vendor/laravel/ai/config/ai.php
```

**Adding a new provider** (e.g. Mistral):

```bash
# 1. Add to config/ai.php (laravel/ai config)
'mistral' => [
    'key' => env('MISTRAL_API_KEY'),
    'url' => 'https://api.mistral.ai/v1',
],

# 2. Add to config/ai-tasks.php (this package)
'mistral' => [
    'model' => 'mistral-large-latest',
    'price' => ['in' => 2.00, 'out' => 6.00],
],
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE.md).
