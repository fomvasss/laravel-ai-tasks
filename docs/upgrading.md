# Upgrading

Changes within 3.x that can affect existing code. The full list is in [CHANGELOG](https://github.com/fomvasss/laravel-ai-tasks/blob/master/CHANGELOG.md).

After every upgrade publish new migrations and check the schema:

```bash
php artisan vendor:publish --tag=ai-migrations
php artisan migrate
php artisan about   # AI Tasks section
```

## 3.39

- `TenantResolver` no longer reads the `X-Tenant-Id` header unless `tenant_header` is configured. An app where that header is how the tenant arrives (set by a gateway, not by the client) adds `AI_TENANT_HEADER=X-Tenant-Id`; otherwise runs resolve to the authenticated user or `default_tenant`. Tasks that override `tenantId()` are unaffected.
- New config key `tenant_header` (`php artisan about` lists it as missing in a published config).

## 3.35

- A run that stops before a tool needing approval gets status `paused` instead of `ok`. Queries and reports filtering `status = 'ok'` miss these runs; `postprocess()`/`onCompleted()` still run for them.
- A paused run is no longer retried when `isAcceptable()` rejects it.
- A pause continued by hand (`AiPayload::$decisions`) is invisible to the package and stays `paused`; continue with `AI::resume()` to have it closed, claimed once and expired.
- `AiResponse` has new trailing constructor arguments `resumeMessages` and `runId`.
- New config section `approvals` (`php artisan about` lists it as missing in a published config).

## 3.34

- Tasks can carry request state into the worker: `ActsAsDispatchingUser`, or `executionContext()` / `withExecutionContext()`. Nothing changes for tasks that don't use them.
- `AiRun::start()` / `startAsQueue()` take a new trailing `$executionContext` argument; direct callers are unaffected.
- Dashboard **Retry** and `ai:retry` rebuild the task under the run's stored context. Runs dispatched before 3.34 have none and are retried as before.

## 3.33

- New `ai_runs.user_id` column — publish and run the migration. Until then runs are stored without it and the dashboard's User filter has no effect.
- `AiContext` has a new trailing constructor argument `userId`. Code that builds an `AiContext` itself is unaffected.

## 3.30

- `AI::queue()` uses the whole routing chain and falls back to the next driver within one attempt. `ai_runs.driver` of a queued run is the driver that answered, not always the first one.
- No fallback when the provider rejects the request (4xx other than 408/429) — `send()`/`stream()` throw `AiDriverException` right away. Before, any error moved on to the next driver.
- `stream()` doesn't switch drivers once output has started.
- `AiRunFailed` fires once per queued run, after the queue gives up — not on every attempt. Between attempts the run stays `running`.

## 3.28 — laravel/ai 1.0

- Requires `laravel/ai` `^1.0`; if the app uses `laravel/mcp` directly, it must be `^1.0`.
- Bedrock: `composer require aws/aws-sdk-php`, laravel/ai no longer installs it.
- Gemini raw `provider_options` use the Interactions API names (`thinkingConfig` → `thinking_level`), see the [laravel/ai upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md).
- `tokens_out` includes reasoning tokens for every provider — runs with Anthropic extended thinking show higher `tokens_out` and `cost`.
- A missing `cache_read`/`cache_write` rate falls back to `in` instead of costing cached tokens as free. Set the rate to `0` to keep the old behaviour.

## 3.27

- New `ai_runs.cost_rates` column — publish and run the migration. Until then runs are stored without it.

## 3.26

- The dashboard has **Retry** / **Dead** actions under `dashboard.middleware`, which defaults to `['web']` — no authorization. Add your auth middleware.
- `ai:request --temperature` is sent only when given.

## 3.24

- A run rejected by the post-call budget check is recorded as `error` (with its cost), not `ok`. Code that sums spend by `status = 'ok'` should use `cost IS NOT NULL`.

## 3.23

- `temperature`, `max_tokens`, `top_p` in payload options now reach the provider — outputs of tasks that already declared them change.
- `viaQueues()['post']` is honoured — make sure workers consume that queue.
- `ai:retry` re-dispatches runs; `--dry-run` keeps the old list-only behaviour.
- Global postprocess pipes also run on the queued path and on `stream()`.
- `AI::fake()` calls `onCompleted()` and fires `AiTaskCompleted` for `send()`/`stream()`.
- `stream()` times out after 60 seconds by default, like `send()`.
- The OpenAI webhook handler verifies Standard Webhooks signatures — set `OPENAI_WEBHOOK_SECRET` to the `whsec_...` value. The `webhook.signature_header` config key was removed.

## 2.x → 3.0

- Engine replaced: `prism-php/prism` → `laravel/ai`. PHP ^8.3, Laravel ^12.
- Config renamed: `config/ai.php` → `config/ai-tasks.php`. `config/ai.php` now belongs to laravel/ai and holds the credentials.
