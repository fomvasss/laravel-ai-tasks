# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.39.0] — 2026-10-08

### Security
- `TenantResolver` took the tenant from the client-supplied `X-Tenant-Id` header, ahead of the authenticated user: any caller of a web or API endpoint that runs an AI task could bill another tenant's budget, dodge their own, and move `ai_runs.tenant_id` — and whatever an app charges by it — to another tenant. The header is now ignored unless named in the new `tenant_header` config (`AI_TENANT_HEADER`). Apps where a trusted gateway sets it add `AI_TENANT_HEADER=X-Tenant-Id`; tasks that override `tenantId()` are unaffected. See [Upgrading](docs/upgrading.md#339).

## [3.38.1] — 2026-10-08

### Fixed
- `SerializesModelsAi` failed on a subclass of a task whose constructor properties are `private` (`ReflectionException: Property ...::$foo does not exist`), already on `AI::send()`, since the idempotency key is built from `serializeForQueue()`. A variant made by subclassing — another `maxSteps()`, `tools()` or TTL — therefore couldn't be run at all. The properties are now read on the class that declares the constructor.

## [3.38.0] — 2026-10-08

### Added
- `AiTask::maxSteps()` — the step budget of the tool loop, passed to `laravel/ai` (`TextGenerationOptions::$maxSteps`). Its default, 1.5× the number of tools (at most 25), cut a task with two or three tools off after 3–4 steps, silently, and there was no way to raise it through the package. `max_steps` in the `AiPayload` options does the same per call and wins over the method.
- `AiTask::approvalTtlMinutes()` — how long this task's pause for tool approval stays resumable; defaults to `approvals.ttl_minutes`, `null` for no limit. One global TTL didn't fit both a chat confirmation (minutes) and a scheduled job whose proposal is answered hours later. On the queued path it's read from the task rebuilt in the worker.

### Changed
- `AiRun::finish()` takes the task as an optional second argument, for its TTL; without it the config value is used as before.

## [3.37.0] — 2026-10-08

### Added
- `AI::dismissPause($runId)` / `AiRun::dismissPause()` close a pause the app won't continue (the user declined, wrote something else): status `ok`, `response.resume.dismissed_at`, no longer resumable. Before, such a run stayed `paused` and showed as open in the dashboard indefinitely.

## [3.36.1] — 2026-10-08

### Fixed
- A refused `AI::resume()`/`queueResume()` (expired pause, missing tool, another task class) left the task instance in resume mode: sending or queuing the same instance afterwards as a normal turn silently built a resume payload again. The resume state is now cleared when the call ends, refused or not.

## [3.36.0] — 2026-10-07

### Added
- `ActsAsDispatchingUser::actingUser()` — whom the task acts as, the authenticated user by default. A task that runs on someone's behalf from a job or webhook returns the user it holds instead of calling `Auth::setUser()` (which, in a long-lived worker, leaks into the next job).

### Changed
- `send()`, `queue()` and `stream()` run under the execution context from the start: `tools()`, `toPayload()` and the tenant/user resolution see the acting user too, not only the provider call. For the default trait (the logged-in user) nothing changes.

## [3.35.0] — 2026-10-07

### Added
- `AI::resume($task, $runId, $decisions)` and `AI::queueResume()` continue a run paused for tool approval. The package stores the whole paused turn with the run — tool calls with result ids and reasoning replay blocks, and the results of tools that ran in the same step — and replays it after the history the task's `toPayload()` returns (with `resumingRun()` set). The pause is claimed atomically (a second resume throws `ApprovalResumeException`), expires after `approvals.ttl_minutes`, and is refused while a tool it waits for is missing from `tools()`. The continuation runs under the paused run's execution context and is linked by `request.meta.resumed_from`.
- Status `paused` for such runs, with a dashboard filter and badge; the monthly cost includes them.
- `AiResponse::$runId` — on every response of `send()`/`stream()` and in the queued `postprocess()`/`onCompleted()`, also when `postprocess()` returned an array; `paused()` and `pendingToolCalls()` (the waiting calls in full form).
- `approvals.reject_reason`: a rejection without a reason carries this text, so the model answers instead of ending with an empty reply.
- `AI::fake()` records `resume()`/`queueResume()`; `assertResumed()`.
- A continuation runs on one driver without fallback — the paused run's unless given — and a queued one is not retried by the queue: the approved tool runs before the provider call, so either would run it twice. A failed continuation is excluded from Retry and `ai:retry`.
- An expired pause is closed on the resume attempt (`ok`, `response.resume.expired_at`), so it doesn't stay open.

### Changed
- A paused run is not retried by `isAcceptable()`.
- Replay blocks of a paused turn are marked with their provider, so a resume on another provider drops them instead of sending them verbatim.

## [3.34.0] — 2026-10-07

### Added
- Execution context: `AiTask::executionContext()` captures request-only state at dispatch (stored with the run as `request.execution_context`, regardless of `store_request`), and the static `withExecutionContext()` applies it around everything the package runs for the task — the provider call with its tool loop and approval checks, `shouldRun()`, `postprocess()`, `onCompleted()`, `onFailed()`, a retry after `isAcceptable()`. Default: nothing is carried.
- `ActsAsDispatchingUser` trait: carries the guard, the user id and the app locale. In the worker the user is re-read on that guard, made the default guard for the call, and everything is restored in `finally` — the next job of the worker never inherits the user. A sync `send()` keeps the request's own user instance. Tools in `AI::queue()` thus act as the user who dispatched them instead of nobody.

### Fixed
- Dashboard **Retry** and `ai:retry` rebuilt the task — `tools()`, the tenant — in the process of whoever clicked, so tools that read the user at dispatch acted as the admin. They now rebuild it under the run's stored context.
- `PostprocessAiResult` built the payload of a retry after `isAcceptable()` with no user or locale; it now runs under the run's context.

## [3.33.0] — 2026-10-07

### Added
- `ai_runs.user_id` — who started the run. Defaults to `auth()->id()` at dispatch (in the request, before a queued job loses the authenticated user); `null` when nobody is logged in. Override `AiTask::userId()` for a task that runs on someone's behalf. Shown in the dashboard next to the tenant, with a **User** filter, and in `AiContext::$userId`.
- New migration `add_user_id_to_ai_runs_table` — publish it (`vendor:publish --tag=ai-migrations`) and migrate. Until then runs are stored without the column, as with `cost_rates`; `php artisan about` lists it as missing.

## [3.32.2] — 2026-10-07

### Changed
- Requires `laravel/ai` `^1.2`. Nothing in the package depends on the new version; the floor moves so the documented way to gate MCP server tools works: since 1.1 `McpServerTool` is `Approvable`, so `(new McpServerTool($tool))->requireApproval()` pauses the call. An MCP tool's own `needsApproval()` is still ignored by the automatic wrapper — see [Tool approval](docs/usage/tool-approval.md#mcp-server-tools).

## [3.32.1] — 2026-10-07

### Fixed
- `postprocess()` of a queued task received an empty `$response->usage`, although `AI::send()` fills it and the run stores tokens and cost. It is now rebuilt from the run: `driver`, `model`, `tokens_in`, `tokens_out`, cache tokens, `cost`, `cost_rates`

## [3.32.0] — 2026-10-07

### Added
- `php artisan about` → **AI Tasks** shows two new rows. `Config` lists keys the published `config/ai-tasks.php` lacks compared with the package's, and keys the package no longer uses — `composer update` never updates a published config, so new settings went unnoticed. `Queue` shows the connection the `ai` queue is worked on (the Horizon supervisor's, when Horizon consumes it) with its `retry_after`, and warns when it isn't above the job timeout: Redis then hands a slow provider call out again while the first copy still runs, and the task executes twice. The application's own lists (`drivers`, `routing`, `budgets`, pipes, middleware) are not compared.
- `AI::fake()` accepts structured answers: an array is returned as `structured` (and as JSON in `content`), an `AiResponse` is returned as is — for testing `schema()` tasks, tool calls and finish reasons through the fake.

## [3.31.2] — 2026-10-07

### Fixed
- A delayed run (`AI::queue(..., delay:)`) no longer counts as stuck before it is due — it was flagged after `stuck_after_minutes` from dispatch, offering Retry in the dashboard and `ai:retry --stuck`.
- Retrying a run whose earlier job is still in the queue no longer executes the task twice: each dispatch now carries an id, and a job whose id is no longer the run's current one skips. A job for a run that is already finished or closed with **Dead** skips too, instead of calling the provider. Jobs queued before the upgrade run as before.

## [3.31.1] — 2026-10-07

### Fixed
- `ai:retry` and the dashboard **Retry** no longer re-dispatch a failed attempt of a sync `send()`/`stream()` call whose fallback driver then answered — the task ran a second time although the call already had its result. Such rows keep status `error` and get `response.superseded_by` with the id of the row that answered.

## [3.31.0] — 2026-10-04

### Added
- Driver state on the dashboard: one row per driver with its current state (`ok` / `degraded` / `down` / `unknown`), last successful answer, last error, and runs / errors / average duration over 24 hours. Needed since 3.30.0: a queued run that switched to a fallback driver keeps only the driver that answered in `ai_runs`, so a provider outage covered by the fallback was invisible. The state counts consecutive transient failures in the cache (`down` after three); rejected requests (4xx) and runs with a tenant's own key are not counted. With several servers, use a shared cache store.

## [3.30.0] — 2026-10-04

### Added
- `AiTask::onFailed(\Throwable|string $reason)` — the counterpart of `onCompleted()`, called exactly once when a task ends without a result: every driver failed (for a queued task, after the queue's last retry), the provider rejected the request, a stream broke off midway, or the budget was exceeded. Until now a task had no way to react to a provider outage — `onCompleted()` only runs once a response exists. An exception thrown from it is logged and never replaces the original error.
- `AiTaskFailedFinally` event — fired together with `onFailed()`; `$run` is `null` when the budget was exceeded before anything started.

### Fixed
- A queued run no longer fires `AiRunFailed` on every failed attempt (up to four times with the default `tries = 3`), nor flips between `dead` and `running` while it retries: between attempts it stays `running` with the last error recorded, and becomes `dead` — firing `AiRunFailed` once — only when the queue gives up. Listeners that notified on `AiRunFailed` were notified repeatedly about one run, sometimes one that then succeeded.

### Changed
- `AI::queue()` now uses the whole `routing` chain, not only its first configured driver: when a driver fails transiently (connection error or timeout, 429, 5xx, insufficient credits), the job tries the next one within the same attempt, and `ai_runs.driver` records the driver that answered. Before, a queued task kept retrying the one provider that was down, and the fallback chain only worked for `send()`/`stream()`. If every driver fails, the job retries the chain from the start (`tries`/`backoff`). Jobs already in the queue at deploy time keep working with their single driver.
- No fallback when the provider rejects the request itself (4xx other than 408/429) — in `send()`, `stream()` and the queue alike: the next provider would get the same request. `send()`/`stream()` throw `AiDriverException` right away, with the original exception as `getPrevious()`. Previously they moved on to the next driver on any error.
- `stream()` no longer switches to the next driver once output has started — it throws `AiDriverException` instead of starting the answer over after the partial text the caller already received.
- A payload with its own key (`providerOverride['key']`) is queued with no fallback drivers: the override replaces the provider for every driver, so a "fallback" would hit the same API with the same key.

## [3.29.0] — 2026-10-04

### Added
- Transcription cost by audio length: `price.per_minute` (or per model under `prices`) is used when the provider reports the duration — whisper-1, Groq, ElevenLabs, Mistral. Without it, or without a duration, transcription is costed by tokens as before (gpt-4o-transcribe, Gemini).
- Transcription usage carries `audio_seconds`, and `model` falls back to the model the provider actually used when the payload sets none.

## [3.28.0] — 2026-10-04

### Changed
- Requires `laravel/ai` `^1.0`. Since 1.0 every gateway reports the full input count (cache included) and exposes `uncachedInputTokens()`, so `tokens_in` is taken from it directly — the per-driver list of gateways with "inclusive" prompt tokens is gone, along with the `cache_inclusive_prompt_tokens` driver option (now ignored). `tokens_in` keeps its meaning: input tokens billed at full price.
- `tokens_out` now includes reasoning tokens for every provider (laravel/ai 1.0 `outputTokens`). Anthropic thinking used to be reported as 0, so runs with extended thinking will show higher `tokens_out` and `cost` — that is the real bill, not a regression.
- Gemini JSON mode sends `response_format` with `mime_type: application/json` instead of `response_mime_type` — Gemini moved to the Interactions API in laravel/ai 1.0, where the old field is not accepted.

### Fixed
- Mistral cache hits are now costed at the cache rate: laravel/ai 1.0 reads its `cached_tokens`.
- Cached tokens are no longer costed as free when the driver's `price` has no `cache_read`/`cache_write` rate — a missing cache rate now falls back to `in`. Found on real runs: OpenAI reports cache writes and Gemini reports implicit cache hits, and a price without those keys silently dropped thousands of input tokens from `cost`. If you relied on the old behaviour, set the cache rate explicitly (`'cache_read' => 0`).
- Default Gemini price gains `cache_read` (0.15, 0.1x `in`).

### Upgrading
- Bedrock: `aws/aws-sdk-php` is no longer installed by laravel/ai — `composer require aws/aws-sdk-php`.
- Gemini raw `provider_options` must use Interactions API names (`thinkingConfig` → `thinking_level`, etc.) — see the [laravel/ai upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md).
- If the app uses `laravel/mcp` directly, it must be `^1.0`.

## [3.27.2] — 2026-10-04

### Fixed
- OpenRouter runs no longer under-report `tokens_in` and `cost` when the prompt hits the cache. laravel/ai 0.11.1 started subtracting cached and cache-written tokens from OpenRouter's prompt count itself, while the package kept subtracting them a second time — so any 0.11.1+ install was losing the cached part of the prompt from both the token count and the bill. `openrouter` is dropped from the cache-inclusive list; `cache_inclusive_prompt_tokens` in the driver config still overrides it either way.

### Changed
- Requires `laravel/ai` `^0.11.1` — the version from which OpenRouter's usage is exclusive. On 0.11.0 the fix above would flip into the opposite error (cache billed twice), so the floor moves with it.

## [3.27.1] — 2026-09-10

### Fixed
- Upgrading to 3.27.0 without publishing and running its migration no longer breaks every run: `cost_rates` is simply left out of the write when the column is not there yet, with one warning in the log telling you what to run. Package migrations are published rather than autoloaded, so there is always a window between `composer update` and `migrate` — and on someone else's project that window can last until they happen to read the changelog. Only the positive answer is cached per process, so runs start recording the column right after `migrate`, without waiting for a worker restart.

### Added
- `php artisan about` now has an **AI Tasks** section showing the runs table and whether its schema is up to date — the place where a pending migration becomes visible without reading release notes.
- `AiRun::forgetSchemaCache()` — drops the cached column check, for tests and for long-lived processes (Octane) that were migrated without a reload.

## [3.27.0] — 2026-09-10

### Added
- Per-model rates: `drivers.<driver>.prices` — a map keyed by model name, checked before the driver-wide `price`. Until now `price` was one set of rates per driver while the model came from `.env`, so pinning a pricier model silently kept costing the old rates and nothing in the data showed it. Keys match both the full model name and the part after `/`, so a gateway-prefixed `anthropic/claude-sonnet-5` matches a `claude-sonnet-5` entry. Models not listed fall back to `price` exactly as before.
- `ai_runs.cost_rates` (new nullable json column) — the rates a run was actually costed with, plus the model and where they came from (`model:<name>` or `driver`). `cost` is computed from config at run time, so without this a row written before a provider price change or a model switch cannot be explained afterwards. It also makes drift detectable: recompute a period from tokens at today's rates and compare with the stored `cost` — a gap means the config changed (or was wrong).
- `Cost::ratesFor($driverCfg, $model)` — resolves the rates for a model and returns the snapshot; `Cost::calcByChars()` now takes an optional `$model` and honours per-model rates too.

### Upgrading
Publish and run the new migration:

```
php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

Nothing else changes: without `prices` the cost of every run is exactly what it was, and `cost_rates` simply starts filling in from the next run. Existing rows keep `cost_rates = null` — their rates are whatever the config held at the time, which is precisely the ambiguity this column removes going forward.

## [3.26.2] — 2026-09-10

### Changed
- Default `deepseek` model in the shipped config is now `deepseek-flash` — the canonical name for V4.1 Flash, released 2026-09-10. The previous default `deepseek-v4-flash` still resolves (DeepSeek routes the legacy name), but it is no longer among the model names the API reports as supported, so pinning to it is a bet on an undocumented alias.
- Default `deepseek` `price` updated to the V4.1 Flash off-peak rates: `in` 0.22 → 0.15, `out` 0.66 → 0.60, `cache_read` 0.007 → 0.003. Republish the config (or copy the `price` block) to pick this up; your own values are never overwritten.

## [3.26.1] — 2026-08-31

### Changed
- `ai:request --temperature` no longer defaults to `0.3` — the parameter is sent only when you pass it. Previously every ad-hoc request carried a temperature nobody asked for, which on many current models is either ignored or rejected. Ad-hoc output will differ slightly from before on providers that honour the parameter; pass `--temperature=0.3` to keep the old behaviour.
- `ai:make-task` stubs and the shipped example tasks no longer declare a `temperature` — they are copied as-is into user code, and the default routing model does not accept it.

### Fixed
- `temperature`/`top_p` set in `AiPayload` options are no longer sent to the Claude models where Anthropic removed sampling parameters (`claude-fable-5`, `claude-mythos-5`, `claude-opus-5`, `claude-opus-4-8`, `claude-opus-4-7`, `claude-sonnet-5`, also recognised behind a gateway prefix such as `anthropic/claude-sonnet-5`) — those reject the parameters with a 400, and `laravel/ai`'s Anthropic gateway passes them through verbatim. This affected the shipped `anthropic` config out of the box, whose default model is `claude-sonnet-5`, and every built-in task that declares a temperature (`ai:request`, `ai:make-task` stubs, the chat and vision examples). Claude models that still accept sampling (Haiku 4.5, Sonnet 4.6, Opus 4.6) and other providers are unaffected.

## [3.26.0] — 2026-08-27

### Added
- Dashboard actions for runs that cannot recover on their own, in a per-row menu (also on the run page): **Retry** rebuilds the task from `ai_runs.request` and re-dispatches it into the same row, **Dead** closes a run you gave up on. Retry covers `error`/`dead` runs and stuck `queued`/`running` ones; a `running` run that is not stuck is left alone, since a worker is still on it. Retry needs `store_request` to have been enabled when the run was recorded — without it there are no constructor arguments to revive. **These run under `dashboard.middleware`, which defaults to `['web']` — no authorization. If you left that default, add your own auth middleware before upgrading, or anyone who can reach the URL can re-dispatch jobs.**
- `stuck` as a first-class state: a run `queued`/`running` for longer than `dashboard.stuck_after_minutes` (new config key, default 15) without progress — the shape a dropped queue payload leaves behind, since nothing remains to fail it. Exposed as a stat card, a status filter, a row badge, `AiRun::stuck()` / `isStuck()` / `canRetry()`, and a `--stuck` flag on `ai:retry`, which previously could not reach these runs at all.
- `AiRun::abandon($reason)` — marks a run dead without firing `AiRunFailed`. The event means the run failed on its own; a human closing a stale row hours later should not notify listeners of a new failure.

### Changed
- The dashboard list now orders by `COALESCE(started_at, created_at)` instead of `started_at`. A never-started run has no `started_at`, and `DESC` puts NULLs first on Postgres but last on MySQL — the same data ordered differently per driver. Queued runs now sort by when they were created, on both.

## [3.25.1] — 2026-08-27

### Fixed
- Default `price` for `openai` and `deepseek` in the shipped config now matches the default model of each and the providers' current published rates (they had drifted to figures from older models). Both gained their cache rates, which were missing entirely: without them a reused prompt is costed as if caching were free, which understates `cost` by several times once a long prefix is being cached — on GPT-5.6 and later a cache *write* is billed at 1.25x the uncached input rate, a read at 0.1x. Republish the config (or copy the `price` blocks) to pick this up; your own values are never overwritten.

## [3.25.0] — 2026-08-27

### Fixed
- `tokens_in` now means the same thing on every driver: input tokens billed at full price, never including cached ones. The `groq`, `openrouter` and `openai-compatible` gateways in `laravel/ai` return prompt tokens *inclusive* of cache hits, so those runs recorded inflated `tokens_in` and `cost` double-counted the cached part (once at full price inside `tokens_in`, once again as `cache_read_tokens`). Runs already stored keep their old values — only new runs are affected.

### Added
- `cache_inclusive_prompt_tokens` (bool) per driver in `config/ai-tasks.php`, overriding the built-in list above. Set it to `true` on `deepseek` if you are pinned to `laravel/ai` < 0.11 (its DeepSeek gateway was inclusive until 0.11.0); set it to `false` to opt a driver out if upstream changes again.

### Note
- `mistral` never reports cache hits at all (`laravel/ai` does not read `prompt_tokens_details.cached_tokens` for it), so cached tokens there are still costed at the full input price. Not fixable from this package — needs an upstream change.

## [3.24.3] — 2026-08-23

### Changed
- Minimum `laravel/ai` version raised to `^0.11` — picks up its fix for stripping markdown code fences (and surrounding prose) from OpenAI-compatible structured output, which also benefits the DeepSeek gateway.

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
