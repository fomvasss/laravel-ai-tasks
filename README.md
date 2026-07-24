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

By default runs are stored in the `ai_runs` table. To use a different table name, set it before running the migration — via `.env`:

```env
AI_TASKS_TABLE=my_ai_runs
```

or in `config/ai-tasks.php`:

```php
'table' => 'my_ai_runs',
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

## Tools & MCP

Override `tools()` on any task to pass tools to the underlying `AnonymousAgent`. Tools are forwarded automatically on `send()`, `stream()`, and `queue()`.

Three approaches are supported:

- **Local tools** — PHP classes implementing `Laravel\Ai\Contracts\Tool`
- **Native MCP** (recommended) — install [`laravel/mcp`](https://github.com/laravel/mcp) and use `Client::web()` or `Client::local()` for HTTP and stdio servers; `laravel/ai` wraps the tool primitives automatically, no supergateway proxy needed
- **HttpMcpClient** — zero-dependency fallback for Streamable HTTP servers when `laravel/mcp` is not installed

**→ [Full documentation: docs/mcp.md](docs/mcp.md)**

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

## Structured Output (Schema)

Implement `AiTask::schema(): ?\Closure` to declare a JSON Schema for the response. Unlike `jsonMode`, the schema is enforced by the provider itself (native structured output on Anthropic, OpenAI, and others via `laravel/ai`'s `StructuredAnonymousAgent`) — the model can't return a shape you didn't ask for.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ClassifyContentTask extends AiTask
{
    // ...

    public function schema(): ?\Closure
    {
        return fn (JsonSchema $schema): array => [
            'category'   => $schema->string()->enum(['tech', 'science', 'politics', 'sport', 'culture', 'business', 'other']),
            'confidence' => $schema->number(),
        ];
    }
}
```

`AiResponse::$structured` is already the decoded array matching your schema — no manual `json_decode()` or markdown-fence stripping needed in `postprocess()`:

```php
public function postprocess(AiResponse $resp): array|AiResponse
{
    return [
        'ok'         => $resp->ok,
        'category'   => $resp->structured['category']   ?? null,
        'confidence' => $resp->structured['confidence']  ?? null,
    ];
}
```

`schema()` takes precedence over `jsonMode` when both are set. It works with `send()` and `queue()` (the closure is wrapped in `Laravel\SerializableClosure\SerializableClosure` automatically, so it survives the queue payload) but is not applied to `stream()`.

## Tool Choice

Implement `AiTask::toolChoice()` to force whether and which tool the model must call, on top of `AiTask::tools()`. Backed by `laravel/ai`'s `ToolChoice` (Gemini, OpenAI, Anthropic).

```php
use Laravel\Ai\ToolChoice;

public function toolChoice(): ToolChoice|string|array|null
{
    return ToolChoice::required;      // model must call some tool
    // return ToolChoice::none;       // model must not call any tool
    // return ToolChoice::tool('current_date'); // model must call this specific tool
    // return 'required';             // string modes are coerced too
}
```

The forced choice is automatically released after the first step, so a forced tool call is still followed by a normal text answer using the tool's result. `toolChoice()` defaults to `null` (provider's own default, usually `auto`) and has no effect without `tools()`.

## JSON Mode

Set `jsonMode: true` on `AiPayload` to tell the model to always respond with valid JSON — no markdown fences, no prose outside the object. Prefer `schema()` above for new tasks; `jsonMode` remains for providers/cases where you don't need a strict shape, or for streaming.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    systemPrompt: 'Classify the text. Reply with {"category": "...", "confidence": 0.0-1.0}.',
    options: ['temperature' => 0.0],
    jsonMode: true,
);
```

The package translates `jsonMode: true` into the correct provider-specific parameter automatically:

| Provider | Mechanism |
|---|---|
| OpenAI | `text.format: {type: json_object}` (Responses API) |
| xAI | `text.format: {type: json_object}` (Responses API) |
| Gemini | `generationConfig.response_mime_type: application/json` |
| DeepSeek, Groq, Mistral, OpenRouter, OpenAI-compatible | `response_format: {type: json_object}` (Chat Completions) |
| Anthropic | no native JSON mode — rely on system-prompt instructions |

> **Tip:** Always describe the expected JSON structure in your `systemPrompt`. `jsonMode` guarantees valid JSON syntax; the shape is still controlled by the prompt.

## Per-request Provider Override

`AiPayload::providerOverride` lets you supply custom API credentials for a single task execution without touching system config or `.env`. Useful when the application manages per-tenant or per-user API keys.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->prompt)],
    systemPrompt: $this->instructions,
    providerOverride: [
        'driver' => 'deepseek',      // any driver supported by laravel/ai
        'key'    => $this->apiKey,   // user-supplied API key
        'model'  => 'deepseek-v4-flash', // optional; overrides driver default
        // 'url'          => '...',  // optional; custom base URL
        // 'organization' => '...',  // optional; OpenAI org scoping
    ],
);
```

How it works:

- A temporary provider config is registered under a deterministic alias (`custom_<hash>`) derived from `driver + key`. The same credentials always resolve to the same alias, so `laravel/ai`'s instance cache is reused within the same process (safe with Horizon).
- The readable `driver` name (e.g. `deepseek`) is recorded in `ai_runs` — not the internal alias.
- If `key` is empty or `providerOverride` is `null`, the task falls back to the system provider.
- If no system driver is configured but `providerOverride` supplies a key, `isConfigured()` is bypassed and the request proceeds with the custom credentials.

**`providerOverride` shape:**

| Field | Type | Required | Description |
|---|---|---|---|
| `driver` | `string` | yes | Provider name (`openai`, `deepseek`, `anthropic`, …). Use `openai-compatible` for self-hosted or third-party endpoints (LM Studio, vLLM, Together, …) instead of overloading `openai` |
| `key` | `string` | yes | API key |
| `model` | `string` | no | Model name (falls back to `options['model']`, then driver default) |
| `url` | `string` | no | Custom base URL (required for `openai-compatible`) |
| `organization` | `string` | no | OpenAI organization ID |

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

> **Note:** `serializeForQueue()` must return only scalar values (strings, ints, arrays of scalars). The array is JSON-encoded and stored in Redis; on the worker side it is passed back into the constructor via `new static(...$args)`. Do not pass Eloquent models — they will be JSON-serialized into a plain array and the constructor will receive an `array` instead of a model instance. Pass IDs instead and load the model inside `toPayload()`.
>
> `serializeForQueue()` also drives idempotency: if it returns `[]` (the default), no deduplication is applied for `AI::queue()`. Any task that accepts constructor parameters influencing the prompt must implement `serializeForQueue()` — `AI::queue()` will throw a `LogicException` at dispatch time if it detects constructor parameters but `serializeForQueue()` returns `[]`.

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

Every run is protected against duplicates via a unique `idempotency_key` stored in `ai_runs`. The key is a hash of `[tenantId, taskName, modality, serializeForQueue()]`.

**Deduplication is active only when `serializeForQueue()` returns a non-empty array.** If it returns `[]` (the default), `idempotencyKey()` returns `null` and no deduplication is applied — multiple runs with the same task can coexist. This means: for any task with variable inputs, implementing `serializeForQueue()` is required both for queue reconstruction and for correct idempotency behavior.

**Collision behavior (when a non-null key already exists in `ai_runs`):**
- `AI::queue()` — returns the existing `run_id`; no duplicate job is dispatched.
- `AI::send()` — always makes a fresh API call; `idempotency_key` is not stored for sync runs.

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

## Modalities

Supports five modalities: `text` · `image` · `embed` · `audio` · `transcription`.

Set `modality()` and `toPayload()` accordingly. For image generation, embeddings, TTS, and transcription see:

**→ [docs/modalities.md](docs/modalities.md)**

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
