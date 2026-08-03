# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.20.0] — 2026-08-04

### Deprecated
- `ChatAssistTask` and `VisionExampleTask` — unused starter examples, not referenced anywhere else in the package. Write your own `AiTask` subclass instead (see "Creating a Task" in the README). Will be removed in the next major version.

## [3.19.1] — 2026-08-04

### Fixed
- `ChatAssistTask`'s `tools` constructor argument was silently ignored — tool-calling never worked with this task. Tools passed to it now reach the model.

## [3.19.0] — 2026-08-03

### Added
- `AiResponse::$toolCalls` — now populated with the tools the model actually called (was always empty).
- `AiResponse::$finishReason` — new field with the model's stop reason (`stop`, `length`, `tool_calls`, `content_filter`, `error`, `unknown`); use it in `isAcceptable()` to detect a truncated response.
- Both fields also work with `AI::queue()`, not only `AI::send()`.

## [3.18.0] — 2026-08-02

### Added
- `AI::prompt()` — quick one-off text prompt without writing a dedicated `AiTask` class. Backed by a new generic `PromptTask`, so it still goes through `send()`: routing, budget checks and `AiRun` tracking apply as usual. Supported by `AI::fake()` too.
- `ai:make-task` stub: trailing comment listing optional `AiTask` hooks (`schema()`, `onCompleted()`, `maxRetries()`/`isAcceptable()`, `tools()`).

## [3.17.0] — 2026-08-02

### Added
- `SerializesModelsAi` trait — lets an `AiTask` constructor accept Eloquent models directly instead of an id; auto-implements `serializeForQueue()`/`fromQueueArgs()`, restoring a fresh model on the worker. `ai:make-task` wires it into every generated stub.
- `docs/mcp.md`: "Which approach do I need?" decision flowchart, and a troubleshooting note on `laravel/mcp` 0.9.0's stricter protocol-version negotiation.

### Fixed
- `onCompleted()` now receives the exact value `postprocess()` returned (array or `AiResponse`), not the `AiResponse`-wrapped value meant for the `AiTaskCompleted` event.
- `AiResponse::$structured` (`schema()` output) is now persisted and restored on the queued path — was always `null` in `postprocess()`/`isAcceptable()` there, even though `send()`/`stream()` had it.
- `docs/mcp.md`/`docs/modalities.md`: stale model names (`gpt-image-1`, `tts-1`) and a wrong method reference (`connectViaStdio()` → `Client::local()`) corrected.

## [3.16.0] — 2026-08-01

### Added
- `AiTask::onCompleted()` — runs once when a task's result is final, without a separate `AiTaskCompleted` listener class.
- `AiTaskCompletedHandlerFailed` event — fired if `onCompleted()` throws.

## [3.15.0] — 2026-07-31

### Added
- `AI::models()` / `Support\ModelLister` — list a driver's available models from its own API.
- `openrouter` pre-configured driver.

### Fixed
- `ai:models` no longer requires `ai.providers.{driver}.url` for OpenAI-compatible drivers.

### Changed
- Bumped stale default models across all drivers.

## [3.14.1] — 2026-07-29

### Added
- Retry dispatches are now logged.

## [3.14.0] — 2026-07-29

### Added
- `maxRetries()` / `isAcceptable()` — opt-in automatic retry (queued path) when a provider returns an unusable "successful" result.
- `AiTaskCompleted::$attemptsExhausted`.

## [3.13.0] — 2026-07-29

### Added
- `AiPayload::$options['provider_options']` — per-driver request fields for schema-based tasks.

## [3.12.0] — 2026-07-29

> ⚠️ Requires `php artisan vendor:publish --tag=ai-migrations && php artisan migrate` — adds `ai_runs.model`, written on every run.

### Added
- `ai_runs.model` column — resolved model name persisted per run.
- Approximate TTS cost tracking (`price.per_char`).

### Fixed
- Migration re-publish no longer duplicates on Laravel 12 anonymous-class migrations.

## [3.11.2] — 2026-07-29

### Fixed
- Image `size` option ignored for anything but `3:2`/`2:3`.
- `TypeError` on audio/embed/transcription tasks (string messages).
- Default OpenAI `audio_model` corrected to `gpt-4o-mini-tts`.

## [3.11.0] — 2026-07-24

### Fixed
- Budget pre-flight check was a no-op — never actually blocked overspend.
- Budget overage after a billed call no longer discards the run's cost.
- `AI::stream()` now enforces budget too.

## [3.9.0] — 2026-07-24

### Fixed
- Default DeepSeek model corrected (`deepseek-chat` alias retired).

## [3.8.0] — 2026-07-24

### Added
- `AiTask::schema()` / `AiResponse::$structured` — native structured output.
- `AiTask::toolChoice()` — force whether/which tool the model must call.

### Fixed
- `send()`/`stream()` no longer leave a run stuck at `running` when the driver throws.

## [3.7.2] — 2026-07-24

### Fixed
- `openai-compatible` driver now gets `jsonMode` support.

## [3.3.2] — 2026-06-27

### Added
- `AiPayload::$jsonMode` — request JSON output natively where supported.

## [3.3.0] — 2026-06-20

### Added
- `AiTask::tools()` — declare tools per task (local + MCP).
- `AiTask::jobTimeout()` — per-task queue timeout.

### Fixed
- `idempotencyKey()` default now actually varies with input (was a static hash).

## [3.2.0] — 2026-06-19

### Added
- `AI::queue(..., delay: ...)`.
- `AiTask::shouldRun()` — pre-execution guard.

## [3.1.0] — 2026-06-19

### Added
- Dashboard live polling.

## [3.0.0] — 2026-06-18

### Changed
- **Engine replaced**: `prism-php/prism` → `laravel/ai`. PHP ^8.3, Laravel ^12 minimums. Config renamed `ai.php` → `ai-tasks.php`.

### Added
- `audio`/`transcription` modalities, file attachments/vision.

## [2.1.0] — 2026-06-18

### Added
- `image` modality, `ai:models` command.

## [2.0.0] — 2026-06-18

### Changed
- Driver architecture unified around Prism, Anthropic/Ollama/Mistral support added, dashboard added, `AI::fake()` added.

## [1.x — initial]

- Initial release: OpenAI + Gemini drivers, routing, `AiRun` model, queue, budget, webhooks.
