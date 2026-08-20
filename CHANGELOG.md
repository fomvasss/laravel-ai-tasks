# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.24.2] — 2026-08-18

### Fixed
- `temperature`/`top_p` set in `AiPayload` options are no longer sent to OpenAI's reasoning models (`gpt-5*` except `gpt-5-chat`, `o1`, `o3`, `o4-mini`) — those reject the parameters with a 400 (`Unsupported parameter`), and `laravel/ai`'s own OpenAI gateway doesn't filter them out. Other providers/models are unaffected.

## [3.24.1] — 2026-08-17

### Fixed
- `AiResponse::$pendingApprovals` was silently lost on the queued path (`AI::queue()`): `AiRun::finish()` never persisted it into the stored run, so `PostprocessAiResult` always reconstructed the response with an empty `pendingApprovals` — a tool-approval pause never reached `postprocess()`/`onCompleted()`. `AI::send()` was unaffected (it doesn't round-trip through run storage).

## [3.24.0] — 2026-08-16

### Changed
- A run rejected by the post-call budget check (provider already billed the request) is now recorded as `error` with its real `cost`/token usage kept, instead of a misleading `ok`. Spend tracking now counts every run with a recorded cost regardless of status, so budget math is unchanged — but anything filtering runs by `status='ok'` will no longer see these runs as successes.
- Attachments are no longer stored verbatim in `ai_runs.request` options (they can hold base64/binary payloads) — replaced with an `[N attachment(s) omitted]` placeholder.

### Fixed
- On Octane, per-request provider overrides (`providerOverride` with a custom key) accumulated one runtime provider alias per unique driver+key pair in config and in laravel/ai's instance cache for the worker's lifetime — both are now flushed between requests.

## [3.23.0] — 2026-08-16

### Added
- `temperature`, `max_tokens`, `top_p` set in `AiPayload` options are now actually sent to the provider (previously silently ignored).
- `AiTask::idempotencyWindow()` — scope queue deduplication to a period instead of forever, e.g. one run per day for the same task+args. Default `null` keeps the existing dedup-forever behavior; existing idempotency keys are unaffected.
- `AI::stream()` now supports `jsonMode` and `toolChoice`, same as `send()`.
- `ai_runs.request` now stores the task's class name, and (when `AI_STORE_REQUEST=true`) its constructor arguments — needed for `ai:retry` and for webhook-driven completion to reconstruct the task.
- `Support\StandardWebhookVerifier` — public helper for verifying Standard Webhooks signatures in a custom `WebhookRegistry::extend()` handler.

### Changed
- `viaQueues()['post']` is now honored — the postprocess step is dispatched to the task's declared `post` queue/connection instead of always the package default queue. Make sure your workers consume that queue before upgrading.
- `ai:retry` now re-dispatches the matching failed runs instead of only listing them; pass `--dry-run` for the old list-only behavior.
- Global postprocess pipes (`ai-tasks.postprocess.pipes`) now also run on the queued path and on `AI::stream()` — previously they only had effect on `send()`. Order is unchanged: pipes run before the task's own `postprocess()`.
- `AI::fake()` now calls `onCompleted()` and fires `AiTaskCompleted` for faked `send()`/`stream()`, and applies the same reconstructability guard to `queue()` as the real dispatcher — tests relying on the old no-op behavior may need adjusting.
- `AI::stream()`'s default timeout is now 60s (previously unset), matching `send()`.

### Fixed
- Webhook-driven completion (`AiRun::markWaiting()` + `POST /ai-webhooks/{driver}`) could never actually run the task's `postprocess()`/`onCompleted()` — the run finished, but nothing downstream fired. Now works whenever the task's constructor arguments were stored (see Added).
- `ai:request --queue` always failed.
- The `QualityScore` example postprocess pipe crashed when enabled.
- Retries triggered by `isAcceptable()` could collide with each other for a task without an idempotency key.
- Unconfigured-driver error referenced the wrong config file.

### Security
- The built-in OpenAI webhook handler now verifies the actual [Standard Webhooks](https://www.standardwebhooks.com/) signature scheme OpenAI uses (`webhook-id`/`webhook-timestamp`/`webhook-signature` headers, with a replay-window check) — the previous scheme did not match what OpenAI sends, so signature verification had no real effect even with a secret configured. If you use `ai-tasks.drivers.openai.webhook.secret`, set it to the `whsec_...` value from your OpenAI webhook settings. The unused `webhook.signature_header` config key was removed.

### Deprecated
- `Support\Pipes\EnsureJson` and `Support\Pipes\SanitizeHtml` — empty example pipes, will be removed in 4.0.

## [3.22.0] — 2026-08-12

### Added
- `AiResponse::$pendingApprovals` — populated when a tool implementing `laravel/ai`'s `Contracts\Approvable` pauses the run instead of executing (`Approval::required()`). Each entry: `id`/`tool`/`arguments`/`reason`.
- `AiPayload::$decisions` — new optional constructor param to resume a run that previously paused with `pendingApprovals`, instead of sending a new text prompt. Accepts a `Laravel\Ai\Approvals\Decisions` instance or a plain `['tool_call_id' => true|false|Decision::approve()|Decision::reject('reason')]` map. See README "Tool Approval" for the full resume flow, including why the paused tool call must be rebuilt from `AiResponse::$toolCalls` rather than `$pendingApprovals`.
- Streaming (`AI::stream()`) does not support `$decisions` yet — only the non-streaming path (`AI::send()`/`AI::queue()`).

### Fixed
- A task combining `tools()` with `decisions` could lose the decision on resume and re-pause instead of continuing.

### Changed
- `laravel/ai` requirement raised from `^0.10` to `^0.10.1`, the earliest version confirmed to ship the `Approvals` namespace this feature depends on.

## [3.21.0] — 2026-08-09

### Added
- `AiTask::tenantId()`, `subjectType()`, `subjectId()` — optional per-task hooks (like `defaultMeta()`, `maxRetries()`) to set `AiContext`/`ai_runs` tenant and subject explicitly, without overriding the whole `context()` method. `tenantId()` returning `null` (default) keeps falling back to `TenantResolver` as before — fully backward compatible.

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
