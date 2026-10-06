# Installation

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13
- [laravel/ai](https://laravel.com/docs/ai-sdk) ^1.0

## Install

```bash
composer require fomvasss/laravel-ai-tasks
```

Publish the configs and run the migrations:

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

`php artisan about` has an **AI Tasks** section that shows whether the `ai_runs` schema is up to date — useful after upgrading, when a new migration has to be published.

## Two config files

| File | Purpose |
|---|---|
| `config/ai.php` | laravel/ai — API keys, provider URLs |
| `config/ai-tasks.php` | this package — models, prices, routing, budgets |

The API key is never stored in `config/ai-tasks.php`. See [Configuration](configuration.md) and [Providers](reference/providers.md).

## Queues and Horizon

Two queues are used by default, split by workload so a burst of slow provider calls can't starve fast postprocessing behind it:

- `ai` — `ProcessAiPayload`, the actual provider call. Slow (seconds), so it needs more processes and a long `timeout`
- `ai-post` — `PostprocessAiResult`, running `postprocess()`/`isAcceptable()` and dispatching retries/completion. Fast and lightweight, so a couple of processes and a short `timeout` are enough

```env
AI_QUEUE=ai
AI_QUEUE_POST=ai-post
```

Example Horizon config:

```php
'supervisor-ai' => [
    'connection' => 'redis',
    'queue' => ['ai'],
    'balance' => 'auto',
    'minProcesses' => 2,
    'maxProcesses' => 20,
    'tries' => 3,
    'timeout' => 300,
],
'supervisor-ai-post' => [
    'connection' => 'redis',
    'queue' => ['ai-post'],
    'balance' => 'simple',
    'minProcesses' => 1,
    'maxProcesses' => 8,
    'tries' => 3,
    'timeout' => 60,
],
```

The supervisor `timeout` must be at least as large as the highest [`jobTimeout()`](usage/queued-tasks.md#job-timeout) of your tasks. The package jobs set their own `tries` (3) and `backoff`, which take precedence over the supervisor's `tries`, see [Failures and job retries](usage/queued-tasks.md#failures-and-job-retries). Tasks can route themselves to other queues via `viaQueues()` — every queue name they return must be consumed by a supervisor.

## Laravel Octane

No configuration needed:

- `TenantResolver` is bound as `scoped` — a new instance per request/job
- The `AiManager` driver cache and runtime provider aliases created by [`providerOverride`](usage/provider-override.md) are flushed on every `RequestReceived` and `TaskReceived` Octane event

A custom `TenantResolver` that holds per-request state is reset correctly between requests thanks to the `scoped` binding.

## Securing the dashboard

The dashboard at `/ai-tasks` is enabled by default with `middleware => ['web']` — no authorization. Before deploying, add your auth middleware, see [Dashboard](usage/dashboard.md).
