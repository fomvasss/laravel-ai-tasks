# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

AI task orchestrator for Laravel. Handles routing, queuing, audit logging, budget tracking, and webhook processing on top of [laravel/ai](https://laravel.com/docs/ai-sdk) as the transport layer.

[Українська документація](README.uk.md)

## Dashboard

Built-in web UI at `/ai-tasks` — runs list with stats, filters, and per-run detail (request, response, tokens, cost).

![Dashboard](art/dashboard.gif)

Configurable via `config/ai-tasks.php`:

```php
'dashboard' => [
    'enabled'    => env('AI_DASHBOARD_ENABLED', true),
    'path'       => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware' => ['web'],
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
    'timeout'      => 120,
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
            modality:     $this->modality(),
            messages:     [new UserMessage("Summarize: {$this->text}")],
            systemPrompt: 'You are a concise summarizer. Reply in 3 sentences max.',
            options:      ['temperature' => 0.3],
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
$this->app->singleton(\Fomvasss\AiTasks\Support\TenantResolver::class, fn() => new MyTenantResolver());
```

## Cost Tracking

Set pricing per driver in `config/ai-tasks.php` (per 1M tokens):

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model'   => 'claude-sonnet-4-6',
    'price'   => [
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
    modality:     'text',
    messages:     [new UserMessage($prompt)],
    systemPrompt: $longSystemPrompt,
    options:      ['cache' => true], // caches systemPrompt on Anthropic
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

Currently configured model is highlighted with `✓`. Providers with a URL in `prism.providers.*.url` (Groq, Mistral, DeepSeek, xAI, Ollama, OpenRouter…) are queried via the OpenAI-compatible `/v1/models` endpoint automatically.

## Supported Providers

Any provider supported by [Prism](https://prismphp.com) works automatically — just add a section to `config/ai-tasks.php` with `api_key` and `model`. No code changes needed.

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

To check what `.env` variables each provider needs, see:

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
