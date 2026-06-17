# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

AI task orchestrator for Laravel. Handles routing, queuing, audit logging, budget tracking, and webhook processing on top of [Prism](https://github.com/echolabs/prism) as the transport layer.

[Українська документація](README.uk.md)

## Requirements

- PHP ^8.2
- Laravel ^11 | ^12 | ^13
- [echolabs/prism](https://github.com/echolabs/prism) ^0.70

## Installation

```bash
composer require fomvasss/laravel-ai-tasks
```

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

Add keys to `.env`:

```env
AI_DEFAULT=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
```

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

use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
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
AI::stream(new SummarizeTask($text), function (string $chunk) {
    echo $chunk;
});

// Override driver at runtime
$response = AI::send(new SummarizeTask($text), drivers: 'anthropic');
```

## Driver Routing

Tasks are routed to drivers via `config/ai.php`:

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
// config/ai.php
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

Set pricing per driver in `config/ai.php` (per 1M tokens):

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

## Artisan Commands

| Command | Description |
|---|---|
| `ai:make-task Name` | Generate a task class |
| `ai:request "prompt"` | Ad-hoc sync or queued request |
| `ai:runs` | List recent ai_runs |
| `ai:budget {tenant}` | Show monthly spend vs limit |
| `ai:retry` | List failed runs for retry |

## Supported Providers

Any provider supported by [Prism](https://github.com/echolabs/prism) works automatically — just add a section to `config/ai.php` with `api_key` and `model`. No code changes needed.

| Provider | Driver key |
|---|---|
| OpenAI | `openai` |
| Anthropic | `anthropic` |
| Google Gemini | `gemini` |
| Ollama (local) | `ollama` |
| Mistral | `mistral` |
| Groq | `groq` |
| xAI (Grok) | `xai` |
| DeepSeek | `deepseek` |
| VoyageAI | `voyageai` |

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE.md).
