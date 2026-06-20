# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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

### Tests
- Added `ToolsTest` (8 cases): `AiTask::tools()` default, override, `AiPayload::tools` storage, tools forwarded to driver on `send()` / `stream()`, empty-tools path, multiple tools

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
