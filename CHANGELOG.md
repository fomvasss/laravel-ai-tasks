# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.11.0] — 2026-07-24

### Fixed
- `Budget::ensureNotExceeded()` pre-flight check was a no-op: `getMonthlyRemaining()` clamps to `max(0.0, ...)`, so a pre-flight call with the default `$expectedCost = 0.0` compared `0.0 < 0.0`, which is never true — a tenant could be arbitrarily far over budget and the check would still pass. It now compares raw `spent + expectedCost` against the limit, unclamped (issue #7)
- `BudgetExceededException` raised after a successful, billed provider call (`send()`, `stream()`, and the queued `ProcessAiPayload::handle()`) no longer discards the run via `fail()` — it's recorded via `finish()` instead, so `cost`/`status = ok` are persisted. Previously the real spend became invisible to `getSpentBetween()` (`status = 'ok' AND cost IS NOT NULL`), silently resetting the tenant's tracked spend on every overage
- `AI::stream()` had no budget check at all after the call completed — streaming requests could spend without limit even with a `monthly_usd` cap configured. It now runs the same post-call check as `send()`
- The queued path still distinguishes a pre-flight throw (run has no response yet — marked `error`, same as before) from a post-call throw (run already has a billed response — marked `ok` with cost, per above)

### Tests
- Added 4 cases to `ExceptionHandlingTest`: `send()` pre-flight blocks before any driver call once already over budget, `send()`/`stream()` keep the run `ok` with cost on post-call overage, queued job fails cleanly (not stuck `running`) on pre-flight overage, queued job keeps the run `ok` with cost on post-call overage

## [3.9.0] — 2026-07-24

### Fixed
- `config/ai-tasks.php`: default DeepSeek model changed from `deepseek-chat` to `deepseek-v4-flash` — DeepSeek's API no longer accepts the `deepseek-chat` alias ("The supported API model names are deepseek-v4-pro or deepseek-v4-flash"), so any consumer relying on the package default without an explicit `DEEPSEEK_MODEL` env var got a hard 400 on every DeepSeek request

## [3.8.0] — 2026-07-24

### Fixed
- `AI::send()` and `AI::stream()` no longer leave the `ai_runs` row stuck at `running` when the driver throws (rate limit, insufficient credits, network error, etc.) — the exception is now caught, the run is marked `error` with `finished_at` set, `AiTaskFailed` fires, and (for `send()`) the fallback chain tries the next configured driver, same as an `AiResponse::$ok === false` result
- `BudgetExceededException` raised after a successful response (post-cost check) is now recorded on the run as `error` before being rethrown, instead of leaving the run orphaned at `running`
- The queue path (`ProcessAiPayload::handle()`) already handled this correctly via `AiRun::markAsDead()`; this fix brings the sync `send()`/`stream()` path to parity

### Added
- `AiTask::schema(): ?\Closure` — declare a JSON Schema for the response; enforced natively by the provider via `laravel/ai`'s `StructuredAnonymousAgent` (Anthropic, OpenAI, and others), instead of relying on `jsonMode` + prompt instructions
- `AiPayload::$schema` — carries the schema closure (wrapped in `Laravel\SerializableClosure\SerializableClosure` so it survives `AI::queue()`'s job serialization)
- `AiResponse::$structured` — the already-decoded array matching the declared schema; no manual `json_decode()`/markdown-fence stripping needed in `postprocess()`
- `schema()` takes precedence over `jsonMode` when both are set on the same task; supported by `send()` and `queue()`, not by `stream()`

- `AiTask::toolChoice(): \Laravel\Ai\ToolChoice|string|array|null` — force whether and which tool the model must call (`auto`/`none`/`required`/a specific tool name), on top of `AiTask::tools()`; backed by `laravel/ai`'s `ToolChoice` (Gemini, OpenAI, Anthropic, 0.9.1+)
- `AiPayload::$toolChoice` — coerced via `ToolChoice::from()`, accepts a `ToolChoice` instance, a mode string, or a `['tool' => 'name']` array
- `Fomvasss\AiTasks\Drivers\Concerns\HasToolChoice` trait + `AnonymousToolChoiceAgent`/`StructuredToolChoiceAgent` — apply the forced tool choice regardless of which agent class (`AnonymousAgent`, `JsonModeAgent`, `StructuredAnonymousAgent`-based) ends up handling the request

### Changed
- `composer.json`: `laravel/ai` floor raised to `^0.10` (was `^0.8`) — tested against v0.10.1; the floor was previously widened to `^0.8|^0.9|^0.10` but our own `Lab::OpenAICompatible` test coverage already requires 0.9+, and 0.x releases carry no cross-minor API guarantee, so there's no real benefit to the wider range

### Tests
- Added `SchemaTest` (10 cases): `AiTask::schema()` default/override, `AiPayload::schema` wrapping, `LaravelAiDriver` agent selection (structured vs jsonMode vs plain, precedence), schema flow through `AI::send()`
- Added `ExceptionHandlingTest` (4 cases): driver throws on `send()`/`stream()` marks the run `error` and rethrows `AiDriverException`, fallback to the next driver after the first throws, `BudgetExceededException` marks the run `error` and rethrows
- Added `ToolChoiceTest` (11 cases): `AiTask::toolChoice()` default, `AiPayload::toolChoice` coercion (instance/string/array), `LaravelAiDriver` agent selection across the schema/jsonMode/plain branches, tool choice flow through `AI::send()`

## [3.7.2] — 2026-07-24

### Fixed
- `JsonModeAgent` now returns `response_format: {type: json_object}` for the `openai-compatible` driver (added in `laravel/ai` 0.9.0), matching the same treatment as DeepSeek/Groq/Mistral/OpenRouter — previously it fell through to an empty provider-options array
- README: `providerOverride` example now recommends `driver: 'openai-compatible'` (with `url`) for self-hosted/third-party endpoints instead of overloading `driver: 'openai'`

## [3.3.2] — 2026-06-27

### Added
- `AiPayload::$jsonMode` (`bool`, default `false`) — set to `true` to request JSON output from the model
- `JsonModeAgent` — internal agent class implementing `HasProviderOptions`; when `jsonMode: true`, it is used instead of `AnonymousAgent` and injects `response_format: {type: json_object}` for providers that support it natively: DeepSeek, Groq, OpenRouter, xAI, Mistral
- OpenAI and other providers that use a different structured-output mechanism are not affected — `jsonMode: true` still works for them via system-prompt instructions without a provider-level `response_format` parameter

### Tests
- Added `JsonModeTest` (14 cases): `AiPayload.jsonMode` default and override, `JsonModeAgent` inheritance, `HasProviderOptions` implementation, correct `response_format` for supported providers, empty options for unsupported providers, string-provider fallback

---

## [3.3.0] — 2026-06-20

### Added
- `AiTask::tools(): array` — declare `Laravel\Ai\Contracts\Tool[]` on a task; tools are merged into `AiPayload` and forwarded to the `AnonymousAgent` before every `send()`, `stream()`, and `queue()` call
- `AiPayload::$tools` — new constructor field (default `[]`) that carries tool instances through the pipeline
- `AiTask::jobTimeout(): int` — per-task queue job timeout in seconds (default `300`); overrides the hardcoded value that was previously fixed at 120 s; `AI::queue()` reads it and passes it to `ProcessAiPayload`
- MCP over Streamable HTTP — tools from any remote MCP server (JSON-RPC 2.0, Bearer auth) can be used without installing `laravel/mcp`; see the **MCP Tools** section in the README

### Changed
- `ProcessAiPayload::$timeout` default raised from `120` to `300` seconds
- `ProcessAiPayload::__construct()` accepts an explicit `int $timeout` argument (set automatically by `AI::queue()` from `$task->jobTimeout()`)
- `LaravelAiDriver::sendText()` and `streamText()` now pass `$p->tools` to `AnonymousAgent` instead of `[]`
- Dashboard pagination switched from `paginate` (full count query) to `simplePaginate` (Prev/Next only, no `COUNT(*)`)
- `config('ai-tasks.dashboard.per_page')` — configurable rows per page (`AI_DASHBOARD_PER_PAGE`, default `50`)
- Dashboard now uses `pagination::simple-tailwind` view (compatible with `simplePaginate`)

### Fixed
- `AiTask::idempotencyKey()` default now hashes `[tenantId, name, modality, serializeForQueue()]` instead of `[name, modality, meta]` — previously tasks with input parameters produced the same key on every call, causing `UniqueConstraintViolationException` on repeat runs
- `AiTask::context()` result cached on the instance (`$cachedContext`) — `TenantResolver` is called once per task instance instead of on every method that reads the context

### Tests
- Added `ToolsTest` (8 cases): `AiTask::tools()` default, override, `AiPayload::tools` storage, tools forwarded to driver on `send()` / `stream()`, empty-tools path, multiple tools

### Octane
- `TenantResolver` binding changed from `singleton` to `scoped` — new instance per request/job, safe for stateful custom resolver implementations
- `AiManager` driver cache is flushed on `RequestReceived` and `TaskReceived` Octane events — prevents stale driver instances accumulating across requests

---

## [3.2.0] — 2026-06-19

### Added
- `AI::queue($task, delay: ...)` — optional dispatch delay (`int` seconds, `DateTimeInterface`, or `DateInterval`)
- `AiTask::shouldRun(): bool` — pre-execution guard called inside the queue job before any API call; return `false` to skip the run (status → `skipped`)
- `config('ai-tasks.dashboard.poll_interval')` — configurable auto-refresh interval in seconds (`AI_DASHBOARD_POLL`, default `3`); set to `0` to disable
- `config('ai-tasks.dashboard.theme')` — default dashboard theme (`AI_DASHBOARD_THEME`): `light` | `dark` | `system` (default); user's manual toggle always takes priority via `localStorage`

### Fixed
- `Router::choose()` was reading routing config from wrong key (`ai.routing.*` → `ai-tasks.routing.*`)
- `FakeAI::queue()` signature updated to accept `$delay` parameter (matches real `AI::queue()`)
- Dashboard `select` was missing `subject_type` / `subject_id` columns

### Tests
- Fixed `Prism\Prism\ValueObjects\Messages\UserMessage` import → `Laravel\Ai\Messages\UserMessage`
- Added `AiTaskTest`: `shouldRun()` default, override, and `AiRun::skip()` behavior
- Added delay tests to `FakeAITest`

---

## [3.1.0] — 2026-06-19

### Added
- Dashboard live polling — table and stats auto-refresh every 8 seconds via JS `fetch`; respects active filters and current pagination page
- `GET /ai-tasks/data` JSON endpoint backing the polling (same filter params as the index page)
- `DashboardController::data()` returns paginated runs + today/month stats as JSON

### Changed
- `DashboardController` refactored: query building and stats calculation extracted to private methods (`buildQuery`, `stats`), shared between `index()` and `data()`
- `subject_type` / `subject_id` added to the dashboard select list (were missing)

---

## [3.0.0] — 2026-06-18

### Added
- `audio` modality — TTS via `Laravel\Ai\Audio` (OpenAI, ElevenLabs, Gemini)
- `transcription` modality — STT via `Laravel\Ai\Transcription` (OpenAI, ElevenLabs, Mistral, Gemini)
- `AiPayload::options['attachments']` — pass file attachments (images, documents) to agent prompt
- Vision support via `Laravel\Ai\Files\Image::fromUrl()` in task attachments
- ElevenLabs driver added to default `config/ai-tasks.php`

### Changed
- **Engine replaced**: `prism-php/prism` → `laravel/ai ^0.8` — all providers now use `AnonymousAgent`
- **PHP minimum raised to ^8.3** (was ^8.2)
- **Laravel minimum raised to ^12.0** (was ^11.0)
- **Config renamed**: `config/ai.php` → `config/ai-tasks.php` (config key `ai-tasks.*`) to avoid conflict with `laravel/ai`'s own `config/ai.php`
- Publish tag renamed: `ai-config` → `ai-tasks-config`
- Credentials (`api_key`) removed from `config/ai-tasks.php` drivers — they live in `laravel/ai`'s `config/ai.php` (`providers.*.key`)
- `AI::isConfigured()` now checks `ai.providers.*.key` (laravel/ai config)
- Message objects changed from `Prism\Prism\ValueObjects\Messages\*` → `Laravel\Ai\Messages\*`
- Streaming now handled natively by laravel/ai (removed all custom SSE code)

### Removed
- `PrismDriver` — replaced by `LaravelAiDriver`
- `prism-php/prism` dependency
- All native SSE streaming code (`streamGemini`, `streamAnthropic`, `streamOpenAICompatible`, `sseLines`)
- `api_key` field from driver config entries

### Migration from v2

```bash
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
```

Rename `config/ai.php` → `config/ai-tasks.php`, remove `api_key` from each driver. Update message imports:

```php
// before
use Prism\Prism\ValueObjects\Messages\UserMessage;
// after
use Laravel\Ai\Messages\UserMessage;
```

---

## [2.1.0] — 2026-06-18

### Added
- `image` modality — generates images via OpenAI Images API (`gpt-image-1`, `dall-e-3`)
- `ai:models` command — lists available models per provider; `--detail` shows token limits, release date, capabilities; auto-fallback to OpenAI-compatible `/v1/models` for unknown providers
- DeepSeek, Groq, Mistral, xAI added to default `config/ai.php`
- Generic OpenAI-compatible SSE stream — any provider with `prism.providers.*.url` gets native streaming automatically

### Fixed
- Dashboard OOM (`Out of sort buffer`) when `ai_runs.response` contains large base64 images — `index()` now selects only needed columns

### Changed
- Gemini price updated to $0.15/$0.60 per 1M tokens (gemini-2.5-flash)

---

## [2.0.0] — 2026-06-18

### Added
- `PrismDriver` — single driver class for all providers via [prism-php/prism](https://github.com/prism-php/prism)
- `AiManager` resolves any Prism provider dynamically from `config/ai.php` — no code changes needed to add new providers
- Support for **Anthropic** (Claude), **Ollama**, **Mistral** providers out of the box
- `AiPayload::$systemPrompt` — dedicated field for system prompt (Prism-native)
- `AiPayload` messages now use Prism message objects (`UserMessage`, `AssistantMessage`, etc.)
- `AiPayload::$options['cache']` — opt-in prompt caching for Anthropic
- `AiPayload::$options['schema']` — structured output via Prism
- Events: `AiTaskQueued`, `AiTaskStarted`, `AiTaskCompleted`, `AiTaskFailed` (business-level)
- `AiRunFinished`, `AiRunFailed` retained as low-level audit events
- `Exceptions/AiDriverException` — typed exception instead of generic `RuntimeException`
- `Exceptions/BudgetExceededException` — moved from `Support/` to `Exceptions/`
- `ai_runs` table: denormalized columns `tokens_in`, `tokens_out`, `cache_read_tokens`, `cache_write_tokens`, `cost` for SQL-level analytics
- Cost calculation supports `cache_write` and `cache_read` token pricing (Anthropic)
- `AI::stream()` now creates and tracks `AiRun` record
- `WebhookController` — merged `DynamicWebhookController` + `WebhooksController` into one
- `declare(strict_types=1)` added to all source files
- `readonly` properties on all DTOs
- `require-dev`: `orchestra/testbench`, `phpunit/phpunit`
- `scripts.test` in `composer.json`
- Built-in dashboard at `/ai-tasks` — runs list with stats, filters, per-run detail (Blade + Tailwind CDN)
- `ai:budget --month`, `--from`, `--to` options for custom period reporting
- `Budget::getSpentBetween()` for arbitrary date range queries
- Native SSE streaming for **OpenAI**, **Gemini**, **Anthropic** — bypasses Prism 0.70 broken stream handlers
- `AI::fake()` — test helper with `assertSent()`, `assertNotSent()`, `assertQueued()`, `assertSentCount()`, `assertNothingSent()`

### Changed
- **PHP minimum raised to ^8.2** (was ^8.1)
- **Laravel minimum raised to ^11.0** (dropped Laravel 10 support); **Laravel 13 supported**
- Queues simplified: `ai` (default) + `ai-post` (postprocess) instead of 5 queues
- `Cost::calc()` now calculates per 1M tokens (not per 1K); returns `null` if no price configured
- `Budget::getMonthlySpent()` now uses `SQL SUM(cost)` instead of PHP-level aggregation
- `AiRun::markAsDead()` reduced to single DB query
- `AiRun::minifyRequest()` no longer stores raw messages (only modality, options, meta)
- `config/ai.php` — removed `task_queues`, `limits` (rpm/tpm) sections
- Publish tags renamed: `config` → `ai-config`, `migrations` → `ai-migrations`

### Removed
- `OpenAiDriver` — replaced by `PrismDriver`
- `GeminiDriver` — replaced by `PrismDriver`
- `AiManager::createOpenaiDriver()`, `createAnthropicDriver()` etc. — replaced by single dynamic `createDriver()`
- `Contracts/QueueSerializableAi` — unified into `AiTask::serializeForQueue()`
- `Contracts/AcceptsWebhooks` — unused
- `Events/AiRunStarted`, `AiRunPostprocessed`, `AiRunPostprocessFailed`
- `Support/BudgetExceededException` — moved to `Exceptions/`
- `Http/Controllers/DynamicWebhookController`
- `Http/Controllers/WebhooksController`
- `image` modality — out of scope for this package
- Rate limiting (`limits` config key) — handle via Horizon worker count

---

## [1.x — initial]

- Initial release: OpenAI + Gemini drivers, routing, AiRun model, queue, budget, webhooks, postprocess pipeline
