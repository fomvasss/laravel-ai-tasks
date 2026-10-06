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

A provider call takes far longer than an ordinary job, and that needs its own queue connection. Redis hands a reserved job out again once the connection's `retry_after` passes — 90 seconds on the default `redis` connection — while a worker may still be on it for up to 300. The result is the same task executed twice. Give the `ai` queue a connection whose `retry_after` is larger than the highest job timeout:

```php
// config/queue.php
'redis-ai' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 360, // > the highest jobTimeout() (300 by default)
    'block_for' => null,
],
```

```php
// config/horizon.php
'supervisor-ai' => [
    'connection' => 'redis-ai',
    'queue' => ['ai'],
    'balance' => 'auto',
    'minProcesses' => 1,
    'maxProcesses' => 6,
    'tries' => 3,
    'timeout' => 300,
],
```

The jobs are still dispatched on the default connection: both connections use the same Redis database and the same queue key, and `retry_after` takes effect when the worker reserves the job. No task needs `onConnection()`.

`ai-post` jobs are short (`postprocess()`, `onCompleted()`) and usually few, so they don't need their own supervisor — add the queue to an existing pool of short jobs on the regular `redis` connection:

```php
'supervisor-default' => [
    'connection' => 'redis',
    'queue' => ['default', 'ai-post'],
    'timeout' => 60,
    // ...
],
```

Rules of thumb:

- the supervisor `timeout` is at least as large as the highest [`jobTimeout()`](usage/queued-tasks.md#job-timeout) of your tasks, and the connection's `retry_after` is larger still
- the package jobs set their own `tries` (3) and `backoff`, which take precedence over the supervisor's `tries`, see [Failures and job retries](usage/queued-tasks.md#failures-and-job-retries)
- every queue a task returns from `viaQueues()` must be consumed by a supervisor
- locally one `ai` process is enough; schedule `horizon:snapshot` so the Horizon metrics are filled

More on running in production — [Production checklist](guides/production.md).

## Laravel Octane

No configuration needed:

- `TenantResolver` is bound as `scoped` — a new instance per request/job
- The `AiManager` driver cache and runtime provider aliases created by [`providerOverride`](usage/provider-override.md) are flushed on every `RequestReceived` and `TaskReceived` Octane event

A custom `TenantResolver` that holds per-request state is reset correctly between requests thanks to the `scoped` binding.

## Securing the dashboard

The dashboard at `/ai-tasks` is enabled by default with `middleware => ['web']` — no authorization. Before deploying, add your auth middleware, see [Dashboard](usage/dashboard.md).
