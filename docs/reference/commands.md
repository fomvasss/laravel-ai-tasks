# Artisan commands

| Command | Description |
|---|---|
| [`ai:make-task`](#aimake-task) | Generate a task class |
| [`ai:request`](#airequest) | Ad-hoc sync or queued request |
| [`ai:runs`](#airuns) | List recent `ai_runs` |
| [`ai:retry`](#airetry) | Re-dispatch failed and stuck runs |
| [`ai:budget`](#aibudget) | Tenant's spend vs limit |
| [`ai:models`](#aimodels) | Models available from a provider's API |

## ai:make-task

```bash
php artisan ai:make-task SummarizeTask
php artisan ai:make-task Orders/AnalyzeTask --queued
```

Creates the class in `App\Ai\Tasks` (subdirectories via `/`), with `SerializesModelsAi`.

| Option | Description |
|---|---|
| `--queued` | Implement `ShouldQueueAi` |
| `--force` | Overwrite an existing file |

## ai:request

```bash
php artisan ai:request "Summarize the plot of Hamlet"
php artisan ai:request "..." --driver=anthropic --raw
php artisan ai:request "..." --queue
```

| Option | Description |
|---|---|
| `--driver=` | Driver name |
| `--modality=text` | `text` or `embed` |
| `--temperature=` | Sent only when given (some models reject it) |
| `--tenant=` | Tenant ID |
| `--queue` | Dispatch to the queue, print the run id |
| `--json` | Print the content only |
| `--raw` | Also print usage: tokens, cost |

## ai:runs

```bash
php artisan ai:runs --task=summarize --status=dead --limit=50
```

| Option | Description |
|---|---|
| `--tenant=` | Filter by tenant |
| `--task=` | Filter by task name |
| `--status=` | Filter by status |
| `--limit=20` | Rows |

## ai:retry

Re-dispatches runs with status `error` or `dead`: rebuilds the task from `task_class`/`task_args` in `ai_runs.request` and re-queues it into the same row (status goes back to `queued`).

```bash
php artisan ai:retry                      # failures from the last 24h
php artisan ai:retry --since=1h --limit=10
php artisan ai:retry --stuck              # also stuck queued/running runs
php artisan ai:retry --dry-run            # list only, change nothing
```

| Option | Description |
|---|---|
| `--since=24h` | Period in hours |
| `--limit=50` | Max runs |
| `--stuck` | Include [stuck](../usage/dashboard.md#stuck-runs) runs |
| `--dry-run` | Only list what would be retried |

Task constructor arguments are stored only with `AI_STORE_REQUEST=true` — runs recorded without them are listed as skipped. Tasks whose constructor has no required parameters can always be retried.

> [!WARNING]
> Every `error` row is picked up — including a failed attempt of a sync call whose fallback driver then answered. Check the list with `--dry-run` first.

## ai:budget

```bash
php artisan ai:budget tenant-abc
php artisan ai:budget tenant-abc --month=2026-09
php artisan ai:budget tenant-abc --from=2026-09-01 --to=2026-09-15
```

| Argument / option | Description |
|---|---|
| `tenant` | Tenant ID, default `default` |
| `--month=` | Month `YYYY-MM` |
| `--from=`, `--to=` | Date range `YYYY-MM-DD`; `--to` defaults to today |

## ai:models

```bash
php artisan ai:models                       # all configured drivers
php artisan ai:models gemini                # one driver
php artisan ai:models openai --filter=gpt-5 # filter by substring
php artisan ai:models anthropic --detail    # token limits, release date, capabilities
```

The currently configured model is marked with `✓`. Groq, Mistral, DeepSeek, xAI, Ollama and OpenRouter are queried via the OpenAI-compatible `/v1/models` endpoint at each provider's default URL; set `ai.providers.{driver}.url` only to override it (e.g. a self-hosted Ollama).

From code — [`AI::models()`](facade.md#listing-models).
