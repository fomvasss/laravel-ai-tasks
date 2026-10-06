# Configuration

`config/ai-tasks.php` holds models, prices, routing and budgets. API keys and provider URLs live in `config/ai.php` (laravel/ai) — see [Providers](reference/providers.md).

## General

| Key | Env | Default | Description |
|---|---|---|---|
| `default` | `AI_DEFAULT` | `openai` | Driver used when no routing rule matches and no driver is passed explicitly |
| `default_tenant` | `AI_DEFAULT_TENANT` | `default` | Tenant ID when the request can't be resolved to a tenant, see [Budgets & tenants](usage/budgets.md) |
| `table` | `AI_TASKS_TABLE` | `ai_runs` | Table for run records |
| `store_request` | `AI_STORE_REQUEST` | `false` | Store messages, system prompt and the task's constructor arguments in `ai_runs.request` |

`store_request` is off by default because prompts may contain sensitive data. Without it a failed run can't be rebuilt: [`ai:retry`](reference/commands.md#airetry), the dashboard **Retry** button and [webhook completion](usage/webhooks.md) need the stored constructor arguments (tasks whose constructor has no required parameters are the exception).

## Dashboard

```php
'dashboard' => [
    'enabled' => env('AI_DASHBOARD_ENABLED', true),
    'path' => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware' => ['web'],
    'poll_interval' => env('AI_DASHBOARD_POLL', 3), // seconds; 0 = off
    'theme' => env('AI_DASHBOARD_THEME', 'system'), // light|dark|system
    'per_page' => env('AI_DASHBOARD_PER_PAGE', 50),
    'stuck_after_minutes' => env('AI_DASHBOARD_STUCK_AFTER', 15),
],
```

> [!WARNING]
> `middleware => ['web']` leaves the dashboard and its Retry / Dead actions open to anyone. In production use e.g. `['web', 'auth']` or `['web', 'auth', 'role:admin']`.

Details — [Dashboard](usage/dashboard.md).

## Queues

```php
'queues' => [
    'default' => env('AI_QUEUE', 'ai'),      // provider calls — slow, needs a long timeout
    'post' => env('AI_QUEUE_POST', 'ai-post'), // postprocess — fast
],
```

A task can override both per class with `viaQueues()`, see [Queued tasks](usage/queued-tasks.md).

## Drivers

The key of each driver must match a provider name in `config/ai.php`.

```php
'drivers' => [
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-5.6-luna'),
        'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'audio_model' => env('OPENAI_AUDIO_MODEL', 'gpt-4o-mini-tts'),
        'price' => [
            'in' => 0.20,
            'out' => 1.20,
            'cache_write' => 0.25,
            'cache_read' => 0.02,
            'per_char' => env('OPENAI_TTS_PRICE_PER_CHAR', 15.0),
        ],
        'webhook' => [
            'secret' => env('OPENAI_WEBHOOK_SECRET'),
        ],
    ],
    // anthropic, gemini, deepseek, groq, mistral, xai, ollama, openrouter, eleven, null
],
```

| Key | Description |
|---|---|
| `model` | Default model for `text` |
| `embed_model` | Model for `embed` |
| `image_model` | Model for `image` |
| `audio_model` | Model for `audio` (TTS) |
| `price` | Rates in USD, `null` — cost is not tracked. See below |
| `prices` | Per-model rates keyed by model name, override `price` |
| `webhook.secret` | Secret for verifying incoming webhooks, see [Webhooks](usage/webhooks.md) |

A task can pick another model per request with `options['model']` in its payload.

### Price keys

| Key | Unit | Used for |
|---|---|---|
| `in` | per 1M tokens | input tokens billed at full price |
| `out` | per 1M tokens | output tokens, reasoning included |
| `cache_read` | per 1M tokens | cached input read; falls back to `in` when missing |
| `cache_write` | per 1M tokens | cached input write; falls back to `in` when missing |
| `per_char` | per 1M characters | `audio` (TTS) — the provider returns no usage, so cost is approximated from the input length |
| `per_minute` | per minute of audio | `transcription`, when the provider reports the duration |

Details and the `prices` override — [Cost tracking](usage/costs.md).

The pre-configured `null` driver returns an empty response — useful for local development.

## Routing

Task name → ordered chain of drivers. The first available one is used, the next is tried on a transient failure.

```php
'routing' => [
    'summarize' => ['openai', 'gemini'],
    'chat' => ['anthropic'],
    'tts' => ['openai', 'eleven'],
],
```

The task name comes from `AiTask::name()` — by default the class name without `Task`, in snake_case. See [Driver routing](usage/routing.md).

## Postprocess pipeline

Global pipes applied to every `AiResponse` before the task's own `postprocess()` — on `send()`, `stream()` and the queued path alike:

```php
'postprocess' => [
    'enabled' => true,
    'pipes' => [
        App\Ai\Pipes\StripMarkdownFences::class,
    ],
],
```

A pipe is a regular Laravel pipeline stage. `AiResponse` is immutable, so return a new instance:

```php
use Fomvasss\AiTasks\DTO\AiResponse;

class StripMarkdownFences
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        return $next(new AiResponse(
            ok: $resp->ok,
            content: preg_replace('/^```\w*\n|\n```$/', '', (string) $resp->content),
            usage: $resp->usage,
            raw: $resp->raw,
            error: $resp->error,
            toolCalls: $resp->toolCalls,
            structured: $resp->structured,
            finishReason: $resp->finishReason,
            pendingApprovals: $resp->pendingApprovals,
        ));
    }
}
```

The bundled `EnsureJson` and `SanitizeHtml` pipes are empty examples, deprecated and removed in 4.0. `QualityScore` is a demo that adds `quality` to `usage`.

## Budgets

Monthly spend limit per tenant in USD:

```php
'budgets' => [
    'default' => ['monthly_usd' => 100],
    'tenant-abc' => ['monthly_usd' => 50],
],
```

See [Budgets & tenants](usage/budgets.md).

## Webhook middleware

```php
'webhook_middleware' => ['api'],
```

Applied to the `POST /ai-webhooks/{driver}` route, see [Webhooks](usage/webhooks.md).
