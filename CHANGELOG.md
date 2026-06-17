# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased — 2.x]

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
