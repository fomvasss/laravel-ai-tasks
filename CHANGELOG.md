# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.14.1] — 2026-07-29

### Added
- `PostprocessAiResult::retry()` now logs (`Log::info`, "AiTask result rejected by isAcceptable(), retrying") each time a retry from 3.14.0 is dispatched — task name, attempt number, both run ids, driver. Without this, a retry was invisible to the consuming app: the retry mechanism dispatches the next attempt internally and only fires `AiTaskCompleted` once, on the final (accepted or exhausted) result, so a listener never sees the intermediate rejections that triggered a retry

## [3.14.0] — 2026-07-29

### Added
- `AiTask::maxRetries(): int` (default `0`) and `AiTask::isAcceptable(AiResponse|array $result): bool` (default `true`) — opt-in retry for the queued path (`AI::queue()`) when a provider responds "successfully" (`ok: true`, no exception) but the postprocessed result is still unusable — e.g. a reasoning model (DeepSeek, Gemini thinking, ...) spending its whole token budget on internal reasoning and returning blank/whitespace content. Previously the only way to handle this was manual, duplicated per-task boilerplate: an `$attempt` constructor param plus hand-rolled `idempotencyKey()`/`serializeForQueue()` changes to dodge the idempotency-key dedup on re-dispatch (see two real instances of this in the itschats project this was extracted from). Task authors now implement two small methods and nothing else — `PostprocessAiResult` owns all retry bookkeeping: it derives the retry idempotency key itself (`$task->idempotencyKey() . '-retry' . $n`, computed outside the task) and dispatches a fresh `ProcessAiPayload`/`PostprocessAiResult` pair reusing the original run's driver. The task class never sees or tracks its own attempt number. Does not apply to `AI::send()`/`AI::stream()` (synchronous paths only fire once, as before)
- `AiTaskCompleted::$attemptsExhausted` (bool, default `false`) — `true` only when `isAcceptable()` rejected the result and `maxRetries()` attempts were already used up. Lets a listener tell "final, exhausted failure" apart from a normal accepted result without re-deriving the task's own `isAcceptable()` check
- `Core\AI::payloadWithTools()` is now `public static` (was `private`) so `PostprocessAiResult` can rebuild the payload for a retry dispatch without duplicating agent/schema/tool-choice wiring
- `AiRun::startAsQueue()` accepts an optional `$idempotencyKey` param to override `$task->idempotencyKey()` — used internally for retry-generation keys
- `ProcessAiPayload`/`PostprocessAiResult` gained an `$attempt` constructor param (default `0`, internal bookkeeping only — never surfaced to task authors)

Covered by 5 new tests (`tests/RetryTest.php`, `Queue::fake()`/`Event::fake()`) plus live end-to-end verification against a real queue (Horizon) and real provider (OpenAI) in a downstream project — including a stale-worker gotcha worth knowing: long-running queue workers keep old bytecode in memory, so a code change to this package during local development needs a worker restart (`horizon:terminate`) to take effect, same as any other Laravel queue worker.

**Known limitation:** the attempt number is not persisted to `ai_runs` — a deliberate trade-off to avoid another "action required" migration release (see 3.12.0 above). It's only recoverable from the `idempotency_key` suffix (`...-retry1`, `...-retry2`, ...); there's no `attempt` column or `retry_of_run_id` link between a run and its retries, so the dashboard doesn't show the chain structurally. No migration is needed for this release — if that ever becomes a real need (reporting/analytics on retry frequency), it's a separate future migration.

## [3.13.0] — 2026-07-29

### Added
- `StructuredToolChoiceAgent` now implements `Laravel\Ai\Contracts\HasProviderOptions`, and `AiPayload::$options['provider_options']` (array keyed by driver name, e.g. `['deepseek' => ['thinking' => ['type' => 'disabled']]]`) flows through `LaravelAiDriver::makeAgent()` into it. Previously a schema-based task (`schema()` set) had no way to pass provider-specific request body fields — `AiPayload` only exposed `model`/`attachments`/`timeout` to the driver, everything else was fixed by the agent class. Scoped per-driver by design (`providerOptions(Lab|string $provider)` returns `[]` for any driver key not present), so setting DeepSeek-only options never leaks into OpenAI/Anthropic/etc. requests — verified both in this package's test suite and against real DeepSeek + OpenAI API calls in a downstream project. Motivated by DeepSeek's `thinking` toggle (`{"type": "disabled"}`), though in practice disabling `thinking` did **not** fix DeepSeek's occasional empty-`content` responses (confirmed via live testing — content came back blank even with `reasoning_tokens: 0`), so no default is applied; the mechanism is kept as general-purpose infrastructure for callers who need provider-specific tuning (e.g. Anthropic extended-thinking budgets, Gemini `thinkingConfig`)

## [3.12.0] — 2026-07-29

> ⚠️ **Action required before deploying this version:** run
> `php artisan vendor:publish --tag=ai-migrations && php artisan migrate`
> in the same deploy as the `composer update`. `AiRun::finish()` now always writes a `model`
> column — without the migration, **every successful `AI::send()`/`AI::queue()` call fails**
> (`SQLSTATE: Unknown column 'model'`), not just the dashboard. This is not an isolated new
> feature — it's on the completion path used by every task.

### Added
- `ai_runs.model` column (migration `add_model_to_ai_runs_table`) — the resolved model name (e.g. `gpt-4o-mini`, `gpt-image-1`, `gpt-4o-mini-tts`) is now persisted for every run. Previously each `LaravelAiDriver::sendX()` method resolved `$model` locally to call the provider but discarded it afterward — nothing in `AiResponse`/`AiRun` carried it, so it was impossible to tell which model actually served a given task without re-reading code/config. `mapUsage()` now takes an optional `$model` param; `sendEmbed()`/`sendAudio()` (which build their usage array by hand) set it directly. Shown in the dashboard table/detail view and in `ai:runs`
- Approximate cost tracking for `audio` (TTS) — OpenAI's `/v1/audio/speech` response carries no usage/token data at all (unlike image/transcription), so `cost` was always `null` for audio runs. Added `Cost::calcByChars()` (input character count × configurable `price.per_char`, per 1M chars) and a new `drivers.openai.price.per_char` config key (env `OPENAI_TTS_PRICE_PER_CHAR`, default `15.0`). `tokens_in`/`tokens_out` stay `null` for audio — this is a cost estimate only, not real usage; verify the default rate against current OpenAI pricing for your model before relying on it

### Fixed
- `AiServiceProvider::boot()` guarded the original `create_ai_runs_table` migration publish with `class_exists('CreateAiRunsTable')`, which never matches (Laravel 12 migrations are anonymous classes) — so every `vendor:publish --tag=ai-migrations` re-copied it under a fresh timestamp, and a subsequent `migrate` failed with "table already exists". Replaced with a glob check (`database/migrations/*_{basename}.php`) that skips publishing a migration whose base name already exists under any timestamp — applies to both the create-table and the new add-model migration, and to any future ones added the same way. Also wrapped migration publishing in `runningInConsole()` to avoid the filesystem check on every web request

## [3.11.2] — 2026-07-29

### Fixed
- `LaravelAiDriver::sendImage()` silently ignored `AiPayload::$options['size']` for any value other than the exact strings `'3:2'`/`'2:3'` — `->landscape()`/`->portrait()` were called only for those two, so a documented value like `'1024x1024'` (used in `docs/modalities.md` and the sandbox `GenerateProductImageTask` example) never reached the provider. Now calls `$pending->size($size)` for any non-empty `size` option, which `laravel/ai`'s `PendingImageGeneration::size()` already accepts as an arbitrary string (exact dimensions or aspect ratio)
- `AiRun::minifyRequest()` threw a `TypeError` (`messageRole(): Argument #1 ($m) must be of type object, string given`) for any `audio`/`embed`/`transcription` task, since those modalities pass plain strings in `AiPayload::$messages` (as documented in `docs/modalities.md`) while the mapper only handled arrays and message objects. Sync `send()` and queued dispatch both call this before the provider request even happens, so every non-array/object message payload was broken. Added a string branch; `messageRole()` now also reads the role off `Laravel\Ai\Messages\Message::$role` (a `MessageRole` enum covering `user`/`assistant`/`tool_result`) instead of a hardcoded `instanceof AssistantMessage` check, so `ToolResultMessage` and any future `Message` subclass are labeled correctly instead of defaulting to `user`
- `config/ai-tasks.php`: default OpenAI `audio_model` was `gpt-4o-audio-preview`, a chat/completions model — the TTS request goes straight to `POST /v1/audio/speech` (`OpenAiGateway::generateAudio()`), which only accepts `tts-1`, `tts-1-hd`, or `gpt-4o-mini-tts`. Default changed to `gpt-4o-mini-tts` (also `laravel/ai`'s own `OpenAiProvider::defaultAudioModel()` default), so `audio` modality works out of the box without an explicit `OPENAI_AUDIO_MODEL` override

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
