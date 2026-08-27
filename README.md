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
    'enabled'       => env('AI_DASHBOARD_ENABLED', true),
    'path'          => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware'    => ['web'],
    'poll_interval' => env('AI_DASHBOARD_POLL', 3),       // seconds; 0 = off
    'theme'         => env('AI_DASHBOARD_THEME', 'system'), // light|dark|system
    'per_page'      => env('AI_DASHBOARD_PER_PAGE', 50),
    'stuck_after_minutes' => env('AI_DASHBOARD_STUCK_AFTER', 15),
],
```

> **Security:** the default `middleware => ['web']` leaves the dashboard open to anyone who can reach the URL — including stored prompts and responses, **and the Retry / Dead buttons below**. In production add your auth middleware: `['web', 'auth']`, or e.g. `['web', 'auth', 'role:admin']` with spatie/laravel-permission.

### Stuck runs

A run counts as **stuck** once it has been `queued` or `running` for longer than `stuck_after_minutes`
without progress. The usual cause is a queue payload that never reached a worker (a Redis restart
between `AI::queue()` writing the row and the worker picking it up): nothing is left to fail the run,
so it stays `queued` forever and no retry mechanism reaches it.

Stuck runs get their own stat card, a `stuck` status filter and a badge on the row. Raise the threshold
above your slowest task's runtime, or long legitimate runs are flagged too.

Two actions are available on each row and on the run page:

- **Retry** — rebuilds the task from `ai_runs.request` and re-dispatches it, reusing the same row.
  Available for `error`/`dead` runs and for stuck `queued`/`running` ones (a `running` run that is not
  stuck is left alone — a worker is still on it). Requires `store_request` to have been enabled when the
  run was recorded, otherwise there are no constructor arguments to revive.
- **Dead** — closes a run you have given up on: `status = dead`, with the reason in `error`. It fires no
  `AiRunFailed` event — the actual failure happened earlier and silently, and consumers listening on that
  event should not be notified about an admin's button click.

The same reconstruct-and-redispatch path is available from the CLI, including stuck runs:

```bash
php artisan ai:retry --since=24h --stuck --dry-run
```

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13
- [laravel/ai](https://laravel.com/docs/ai-sdk) ^0.10

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

use App\Models\Article;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;

class SummarizeTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(
        private readonly Article $article,
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage("Summarize: {$this->article->body}")],
            systemPrompt: 'You are a concise summarizer. Reply in 3 sentences max.',
            options: ['temperature' => 0.3],
        );
    }

    public function schema(): ?\Closure
    {
        return fn (JsonSchema $schema): array => [
            'summary' => $schema->string(),
        ];
    }

    public function postprocess(AiResponse $response): array
    {
        // shape the raw response into your own result format — runs on every attempt,
        // including attempts a later isAcceptable() rejects, so keep this side-effect free
        return ['summary' => $response->structured['summary'] ?? ''];
    }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        // runs exactly once, only for the final result — this is where side effects belong
        $this->article->update(['summary' => $result['summary'] ?? null]);
    }
}
```

`private readonly Article $article` — a plain Eloquent model, not an id — works because of `use SerializesModelsAi;` (added by `ai:make-task` automatically): it handles `serializeForQueue()`/`fromQueueArgs()` for you, restoring a fresh `$article` on the worker for every queued run. `schema()` guarantees the provider replies with exactly `{"summary": "..."}`, decoded into `AiResponse::$structured` — see [Structured Output](#structured-output-schema) below. `postprocess()` shapes the response; `onCompleted()` acts on the final one. See [The `onCompleted()` Hook](#the-oncompleted-hook) below for the full guarantee (called once, after retries settle, isolated from the pipeline if it throws).

## Running Tasks

```php
use Fomvasss\AiTasks\Facades\AI;

// Sync
$response = AI::send(new SummarizeTask($article));
echo $response->content;

// Async (queue)
$runId = AI::queue(new SummarizeTask($article));

// Streaming
$response = AI::stream(new SummarizeTask($article), function (string $chunk) {
    echo $chunk;
});
// $response->content — full accumulated text
// $response->usage  — tokens + cost (same as AI::send)

// Override driver at runtime
$response = AI::send(new SummarizeTask($article), drivers: 'anthropic');
```

### Quick Prompts

For a one-off call that doesn't warrant a dedicated `AiTask` class, use `AI::prompt()`. It still goes
through `send()` — routing, budget checks and `AiRun` tracking apply as usual:

```php
$response = AI::prompt('Як справи?');
echo $response->content;

// With a system prompt, explicit driver, and a custom dashboard/routing name
$response = AI::prompt(
    prompt: 'Summarize this in one sentence: ...',
    system: 'You are a terse assistant.',
    drivers: 'anthropic',
    name: 'quick_summary',
);
```

All runs default to the `prompt` task name in the dashboard unless `name` is given. For anything
reused, queued, or needing `postprocess()`/`schema()`/`tools()`, write a proper `AiTask` instead.

## Streaming

`AI::stream()` delivers response text chunk by chunk via a callback, useful for real-time UI (SSE, WebSockets).

```php
$response = AI::stream(
    new SummarizeTask($article),
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

## Generation Options

For text tasks, `temperature`, `max_tokens` and `top_p` set in `AiPayload` options are passed through to the provider (any of them may be omitted):

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    options: ['temperature' => 0.3, 'max_tokens' => 1024, 'top_p' => 0.9],
);
```

> **Upgrade note:** before v3.23.0 these options were silently ignored. If your existing tasks already declare `temperature`, their outputs will change after upgrading — the value now actually reaches the provider.

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
AI::send((new SummarizeTask($article))->viaDrivers('gemini'));
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

> **Security:** the default resolver trusts the client-supplied `X-Tenant-Id` header — any caller can bill another tenant's budget (or dodge their own) by setting it. If budgets matter and the header isn't set by trusted infrastructure only, bind a resolver that derives the tenant from the authenticated user instead of the header.

A custom `TenantResolver` only has access to the current request/auth state — nothing task-specific. When a task already knows its own tenant (e.g. it holds an Eloquent model with an `organization_id`), override `tenantId()` on the task itself instead — no service provider binding needed, and it takes priority over `TenantResolver`:

```php
protected function tenantId(): ?string
{
    return $this->order->organization_id; // null falls back to TenantResolver, as usual
}
```

Pair it with `subjectType()`/`subjectId()` to tag the run with the specific record it concerns (e.g. `'order'` / `$this->order->id`) — independent of `tenantId()`, purely for filtering `ai_runs` by subject instead of only by tenant/task:

```php
protected function subjectType(): ?string { return 'order'; }
protected function subjectId(): ?string { return $this->order->id; }
```

`Fomvasss\AiTasks\Exceptions\BudgetExceededException` is thrown once a tenant's monthly spend would exceed `monthly_usd` — checked both pre-flight (before the provider call, using prior spend) and post-call (after, using the actual cost of the response) on `send()`, `stream()`, and the queued job. If the exception fires post-call, the response was already billed — the run is recorded as `error` but keeps its real `cost`/token usage, and spend tracking counts every run with a recorded cost regardless of status, so nothing vanishes from future budget checks.

> **Note:** budget checks are advisory, not a hard guarantee — concurrent jobs each pass the pre-flight check against the same prior spend, so several in-flight requests can collectively overshoot the limit by up to their combined cost. Treat `monthly_usd` as a soft cap with at most a few requests of drift, not an exact billing ceiling.

## Cost Tracking

Set pricing per driver in `config/ai-tasks.php` (per 1M tokens):

```php
'anthropic' => [
    'model' => 'claude-sonnet-5',
    'price' => [
        'in'          => 3.00,
        'out'         => 15.00,
        'cache_write' => 3.75,  // prompt caching write
        'cache_read'  => 0.30,  // prompt caching read
    ],
],
```

Cost is calculated after each response and stored in `ai_runs.cost`. If `price` is not set, `cost` is `null` but token counts are always saved.

`tokens_in` always counts **only input tokens billed at full price** — cached ones are reported separately as `cache_read_tokens`/`cache_write_tokens` and never included, whichever driver you use. Providers disagree on this (Anthropic and Bedrock Converse exclude cache hits from their input count; OpenAI, Gemini, DeepSeek, Groq and the OpenAI-compatible APIs include them), and so do the gateways in `laravel/ai`, so the difference is normalized here. Override per driver if your `laravel/ai` version behaves differently:

```php
'deepseek' => [
    'model' => 'deepseek-v4-flash',
    // laravel/ai < 0.11 reported DeepSeek prompt tokens inclusive of cache hits
    'cache_inclusive_prompt_tokens' => true,
    'price' => ['in' => 0.22, 'out' => 0.66, 'cache_read' => 0.007],
],
```

> `mistral` is a known gap: `laravel/ai` does not read its `cached_tokens` at all, so cache hits there are costed at the full input price.

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

Implement `AiTask::schema(): ?\Closure` to declare a JSON Schema for the response — `SummarizeTask` in [Creating a Task](#creating-a-task) already does this (`'summary' => $schema->string()`). Unlike `jsonMode`, the schema is enforced by the provider itself (native structured output on Anthropic, OpenAI, and others via `laravel/ai`'s `StructuredAnonymousAgent`) — the model can't return a shape you didn't ask for. `AiResponse::$structured` is the already-decoded array matching that schema — no manual `json_decode()` or markdown-fence stripping needed in `postprocess()`.

`schema()` takes precedence over `jsonMode` when both are set. It works with `send()` and `queue()` (the closure is wrapped in `Laravel\SerializableClosure\SerializableClosure` automatically, so it survives the queue payload) but is not applied to `stream()`.

### Field Types & Nesting

`JsonSchema` supports the usual field types plus nested objects and optional fields — a realistic schema (e.g. a chat-assistant reply that sometimes captures contact details) combines them:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'action'     => $schema->string()->enum(['reply', 'escalate_to_human']),
        'confidence' => $schema->number(),
        'urgent'     => $schema->boolean(),
        'contact'    => $schema->object([
            'name'  => $schema->string(),
            'email' => $schema->string(),
        ])->nullable(), // the whole object, or null when there's nothing to report
    ];
}
```

Structured output is always a top-level **object** — a task whose natural result is a list (e.g. extracted keywords) has to wrap it under a key, then unwrap it in `postprocess()`:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'keywords' => $schema->array()->items($schema->string()),
    ];
}

public function postprocess(AiResponse $resp): array
{
    return ['keywords' => $resp->structured['keywords'] ?? []];
}
```

### Provider-Specific Options

For schema-based tasks, pass provider-specific request fields via `AiPayload::$options['provider_options']` — an array keyed by driver name. Only the matching driver receives its entry; every other provider's request is left untouched.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    systemPrompt: $this->instructions,
    options: [
        'temperature' => 0.3,
        'provider_options' => [
            'deepseek' => ['thinking' => ['type' => 'disabled']], // DeepSeek-only, ignored by other drivers
        ],
    ],
);
```

Backed by `laravel/ai`'s `HasProviderOptions` contract — `StructuredToolChoiceAgent::providerOptions(Lab|string $provider)` returns the array set for the resolved driver, or `[]` if nothing was configured for it. Useful for provider-native knobs the package doesn't wrap explicitly (DeepSeek `thinking`, Anthropic extended-thinking budgets, Gemini `thinkingConfig`, …). Only applies when `schema()` is used (`StructuredToolChoiceAgent`); has no effect with `jsonMode` or plain-text tasks.

## Response Metadata

`AiResponse` carries a couple of fields beyond `content`/`structured`, both survive the queued round-trip (persisted in `ai_runs.response`, restored for `postprocess()`):

- `AiResponse::$toolCalls` — the tools the model actually invoked for this response (`array<int, array{id: string, name: string, arguments: array, ...}>`, one entry per `Laravel\Ai\Responses\Data\ToolCall::toArray()`). Empty when the model didn't call any tool.
- `AiResponse::$finishReason` — the last generation step's stop reason from `laravel/ai` (`stop`, `length`, `tool_calls`, `content_filter`, `error`, `unknown`). Useful in `isAcceptable()` to tell a genuinely truncated response (`length`) apart from other rejection reasons instead of guessing from an empty `content`.

`AiResponse::$raw` exists but is currently always empty — its intended source (`providerContentBlocks`) is dropped by `laravel/ai` before it reaches a `StructuredAgentResponse`, so it isn't populated for `schema()`-based tasks.

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

## Tool Approval

For a tool returned from `AiTask::tools()` that performs an irreversible or expensive action (place an order, delete a file, send money), have it implement `laravel/ai`'s native `Contracts\Approvable` (see `laravel/ai`'s own docs for the full contract — `requireApproval()`/`withoutApproval()`/`shouldRequestApproval()`). This package does not add its own approval protocol — it only surfaces what `laravel/ai` already does:

```php
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;

class CreateOrderTool implements Tool, Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Placing a real order requires customer confirmation.');
    }
}
```

When the model tries to call an `Approvable` tool that requires approval, the run pauses instead of executing it — `AiResponse::$pendingApprovals` is populated (`id`/`tool`/`arguments`/`reason` per pending call) and the tool is **not** run.

To resume, dispatch the same task again with `AiPayload::$decisions` set instead of building a new text prompt:

```php
public function toPayload(): AiPayload
{
    return new AiPayload(
        modality: 'text',
        messages: $this->history(), // must include the paused assistant turn with its tool call
        decisions: $this->decisions, // e.g. ['call_abc123' => true] — null on the first, proposing call
    );
}
```

`decisions` accepts a `Laravel\Ai\Approvals\Decisions` instance or a plain `['tool_call_id' => true|false|Decision::approve()|Decision::reject('reason')]` map.

**Deliberately not built on `laravel/ai`'s `RemembersConversations`/`ConversationStore`** — this package assumes your own domain data (chat, message log, etc.) is already the source of truth for conversation history, and `AiPayload::$messages` is always built from it. Adding a second, `laravel/ai`-owned conversation table would duplicate that. This means resuming correctly is on you: your task's history builder must reconstruct the paused assistant turn as a real message with its tool call attached, not just the text summary the user saw — see `laravel/ai`'s docs on `Approvable`/`PendingApproval` for the exact shape.

**Reconstruct the paused turn from `AiResponse::$toolCalls`, not `$pendingApprovals`.** `$pendingApprovals` is a reduced view for display (`id`/`tool`/`arguments`/`reason` only). `$toolCalls` carries the full shape a replay actually needs — `result_id` and, for reasoning-model providers (e.g. OpenAI's Responses API with a reasoning model), `reasoning_id`/`reasoning_summary`/`reasoning_encrypted_content`. Rebuild the tool call with `Laravel\Ai\Responses\Data\ToolCall::fromArray($entry)` rather than hand-picking fields — a replay missing `result_id` is rejected outright (`400: input[N].call_id: expected a string, but got null`):

```php
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;

// $pendingToolCall = the matching entry from the PROPOSING call's AiResponse::$toolCalls
$messages[] = new AssistantMessage('', new Collection([
    ToolCall::fromArray($pendingToolCall),
]));
```

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

Implement `ShouldQueueAi` for queue/connection routing; `use SerializesModelsAi;` handles `serializeForQueue()`/`fromQueueArgs()` automatically:

```php
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;

class AnalyzeTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(private readonly int $productId) {}

    public function viaQueues(): array
    {
        return ['request' => 'ai', 'post' => 'ai-post'];
    }
}
```

The `request` stage is the API call (`ProcessAiPayload`), `post` is the postprocess job (`PostprocessAiResult`). Make sure your Horizon supervisors actually consume every queue name you return here — a job dispatched to a queue nobody listens to sits there forever.

> **Upgrade note:** before v3.23.0 the `post` stage was ignored and postprocess jobs always went to `config('ai-tasks.queues.post')`. If your tasks already declare a custom `post` queue, it takes effect after upgrading — add it to your worker config first.

> **Note:** `serializeForQueue()` must return only scalar values (strings, ints, arrays of scalars) — this array is passed back into the constructor on the worker via `new static(...$args)`. `SummarizeTask` in [Creating a Task](#creating-a-task) shows the easier path — `use SerializesModelsAi;` lets the constructor take an Eloquent model directly (a promoted property), instead of an id you'd reload by hand in `toPayload()`. `serializeForQueue()` also drives idempotency — see [Idempotency](#idempotency).

### Delayed dispatch

Pass a `delay` to `AI::queue()` to defer execution:

```php
AI::queue(new SummarizeTask($article), delay: 300);                 // 5 minutes (seconds)
AI::queue(new SummarizeTask($article), delay: now()->addHours(2));  // Carbon
AI::queue(new SummarizeTask($article), delay: new \DateInterval('PT10M'));
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

**Deduplication is active only when `serializeForQueue()` returns a non-empty array.** If it returns `[]` (the default), `idempotencyKey()` returns `null` and no deduplication is applied — multiple runs with the same task can coexist. This means: for any task with variable inputs, implementing `serializeForQueue()` is required both for queue reconstruction and for correct idempotency behavior. `AI::queue()` enforces this at dispatch time — it throws a `LogicException` for a task that has constructor parameters but whose `serializeForQueue()` returns `[]`.

**Collision behavior (when a non-null key already exists in `ai_runs`):**
- `AI::queue()` — returns the existing `run_id`; no duplicate job is dispatched.
- `AI::send()` — always makes a fresh API call; `idempotency_key` is not stored for sync runs.

What matters for custom deduplication is what `serializeForQueue()` includes — the default `idempotencyKey()` just hashes it as-is, no override needed:

```php
class ChatTask extends AiTask
{
    use SerializesModelsAi;

    public function __construct(
        private readonly string $question,
        private readonly string $messageId, // unique per message from the chat system
        private readonly array  $history = [],
    ) {}
}
```

For chat/assistant integrations where the same question can be asked multiple times: as long as the conversation history (or a `messageId`) is part of the constructor, each turn produces a different key and idempotency works correctly — it only blocks genuine technical duplicates (double-send, queue retry).

**Deduplication window.** By default the unique key never expires — the same task+args will never be dispatched twice, ever. Override `idempotencyWindow()` to scope deduplication to a period; the returned string becomes part of the key, so the task may run again once the window changes:

```php
public function idempotencyWindow(): ?string
{
    return now()->format('Y-m-d'); // at most one run per day for the same args
}
```

Tasks that keep the default `null` are unaffected — their existing keys stay stable across upgrades.

### Retrying an Unacceptable Result

A provider can respond "successfully" (`ok: true`, no exception) with a result that's still unusable — most commonly a reasoning model (DeepSeek, Gemini thinking, ...) spending its whole token budget on internal reasoning and returning blank/whitespace content. Implement `maxRetries()` and `isAcceptable()` to retry automatically before giving up:

```php
class ChatReplyTask extends AiTask implements ShouldQueueAi
{
    // ...

    public function maxRetries(): int
    {
        return 1;
    }

    public function isAcceptable(AiResponse|array $result): bool
    {
        return !empty($result['ok']) && trim(strip_tags($result['message'] ?? '')) !== '';
    }
}
```

`isAcceptable()` receives whatever `postprocess()` returned. No other change is needed — no `$attempt` constructor param, no `idempotencyKey()`/`serializeForQueue()` changes. `PostprocessAiResult` owns all the retry bookkeeping: it derives the retry's idempotency key itself (`idempotencyKey() . '-retry' . $n`) and dispatches a fresh `ProcessAiPayload`/`PostprocessAiResult` pair on the same driver as the original run. The task class never sees or tracks its own attempt number.

Once retries are exhausted (or immediately, if `maxRetries()` is `0`, the default), the run is final — check `attemptsExhausted` in [`onCompleted()`](#the-oncompleted-hook) below to tell an unresolved failure apart from a normal accepted result, without re-deriving `isAcceptable()` yourself. Only applies to the queued path (`AI::queue()`); `AI::send()`/`AI::stream()` are synchronous and always fire once.

> **Note:** the attempt number is not persisted to `ai_runs` — only recoverable from the `idempotency_key` suffix (`...-retry1`, `...-retry2`, ...). There is no `attempt` column or a link between a run and its retries, so the dashboard doesn't show the retry chain structurally.

### The `onCompleted()` Hook

For a task with a single consumer, override `onCompleted()` instead of writing a separate `AiTaskCompleted` listener:

```php
class GenerateChatAssistantReplyTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(
        private readonly ChatMessage $message,
    ) {}

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        if ($attemptsExhausted) {
            SetManagerAction::run($this->message);
            return;
        }

        // save the message, broadcast it over the websocket, etc.
    }
}
```

It's called exactly once, at the same point `AiTaskCompleted` fires — after `postprocess()`/`isAcceptable()` have settled on a final result (accepted, or retries exhausted). It does not run on rejected intermediate retry attempts. `$attemptsExhausted` means the same thing as `AiTaskCompleted::$attemptsExhausted`.

An exception thrown from `onCompleted()` is caught and logged, and fires `AiTaskCompletedHandlerFailed` — it never breaks the package's own pipeline or stops `AiTaskCompleted` from firing.

Keep using an `AiTaskCompleted` listener when several independent consumers need to react to the same task's completion without editing the task itself (e.g. one persists a domain record, another writes to analytics). Both can be used together — the package calls `onCompleted()` and fires the event at the same moment.

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
| `AiTaskCompletedHandlerFailed` | `AiTask::onCompleted()` threw |
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
| `ai:retry` | Re-dispatch failed runs (`--dry-run` to list only) |

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

Currently configured model is highlighted with `✓`. Groq, Mistral, DeepSeek, xAI, Ollama and OpenRouter are queried via the OpenAI-compatible `/v1/models` endpoint automatically — using each provider's default API URL out of the box; set `ai.providers.{driver}.url` only to override it (e.g. a self-hosted Ollama instance).

Same listing also available from your own code — `AI::models()` resolves credentials from `config/ai.php` itself, same as `send()`/`queue()`/`stream()`:

```php
use Fomvasss\AiTasks\Facades\AI;

$models = AI::models('openai', filter: 'gpt');
// [['id' => 'gpt-5.6-luna', 'display_name' => null, 'owner' => 'system', 'created' => '2026-06-23', ...], ...]
```

Throws `Fomvasss\AiTasks\Exceptions\AiDriverException` if the driver has no `api_key` in `config/ai.php`, `ModelListingUnavailableException` if it has no listing endpoint, or `ModelListingException` on a connection/API error.

The fetching logic itself lives in `Fomvasss\AiTasks\Support\ModelLister` (used internally by `AI::models()`) — inject or `new` it directly if you already have credentials at hand and want to skip the config lookup:

```php
use Fomvasss\AiTasks\Support\ModelLister;

$models = app(ModelLister::class)->forDriver('openai', ['api_key' => config('ai.providers.openai.key')], filter: 'gpt');
```

### ai:retry

Re-dispatches runs with status `error` or `dead` by reconstructing the task from the `task_class`/`task_args` stored in `ai_runs.request` and re-queuing `ProcessAiPayload` for the same run (status is reset to `queued`):

```bash
php artisan ai:retry                 # retry failures from the last 24h
php artisan ai:retry --since=1h --limit=10
php artisan ai:retry --dry-run       # list what would be retried, change nothing
```

Task ctor args are stored only when `AI_STORE_REQUEST=true` (they usually contain the prompt text) — runs recorded without them are listed as skipped. Tasks whose constructor takes no required parameters can always be retried.

## Async Providers & Webhooks

For providers that finish work out-of-band (batch APIs, long-running generations), a run can wait for a webhook instead of blocking a worker. The flow is app-driven:

1. Your driver/task code submits the provider job, then parks the run:

```php
$run->markWaiting(['provider_run_id' => $providerJobId]);
```

2. The provider calls `POST /ai-webhooks/{driver}`. The built-in handler (registered for `openai`; add your own via `WebhookRegistry::extend()`) verifies the signature when `ai-tasks.drivers.{driver}.webhook.secret` is set, finds the waiting run by `provider_run_id`, and finishes it.

3. If the run's task can be reconstructed (its `task_class` is stored automatically; ctor args require `AI_STORE_REQUEST=true`, or a task with no required ctor params), a `PostprocessAiResult` job is dispatched — `postprocess()`, `isAcceptable()`/retries and `onCompleted()` run exactly as on the normal queued path. Otherwise the run is finished as-is and a warning is logged.

The OpenAI handler verifies [Standard Webhooks](https://www.standardwebhooks.com/) signatures (`webhook-id`/`webhook-timestamp`/`webhook-signature` headers, HMAC-SHA256, ±5 min replay tolerance) using the `whsec_...` secret from your OpenAI dashboard webhook settings — set it as `OPENAI_WEBHOOK_SECRET`. `Fomvasss\AiTasks\Support\StandardWebhookVerifier::verify()` is reusable for a custom handler that needs the same scheme.

> **Security:** without a configured `webhook.secret` the endpoint accepts unsigned requests. Set the secret in production. If you register a handler for a provider that doesn't use Standard Webhooks, verify with that provider's own scheme inside your `WebhookRegistry::extend()` closure instead.

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
| OpenRouter | `openrouter` | ✅ |
| VoyageAI | `voyageai` | add manually |
| AWS Bedrock | `bedrock` | add manually |
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

## Support

If this package is useful to you, consider supporting its development:

[![Monobank](https://img.shields.io/badge/Donate-Monobank-black)](https://send.monobank.ua/jar/5xsqtHvVrY)
[![Ko-Fi](https://img.shields.io/badge/Donate-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/fomvasss)
[![USDT TRC20](https://img.shields.io/badge/Donate-USDT%20TRC20-26A17B?logo=tether&logoColor=white)](https://link.trustwallet.com/send?coin=195&address=THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf&token_id=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t)

> USDT TRC20: `THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf`
