# Dashboard

Built-in web UI at `/ai-tasks` — runs list with stats, filters, and per-run detail (request, response, tokens, cost).

![Dashboard](../images/dashboard.gif)

```php
// config/ai-tasks.php
'dashboard' => [
    'enabled' => env('AI_DASHBOARD_ENABLED', true),
    'path' => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware' => ['web'],
    'poll_interval' => env('AI_DASHBOARD_POLL', 3), // seconds; 0 = off
    'theme' => env('AI_DASHBOARD_THEME', 'system'), // light|dark|system
    'per_page' => env('AI_DASHBOARD_PER_PAGE', 50),
    'stuck_after_minutes' => env('AI_DASHBOARD_STUCK_AFTER', 15),
],
```

> [!WARNING]
> The default `middleware => ['web']` leaves the dashboard open to anyone who can reach the URL — including stored prompts and responses, **and the Retry / Dead buttons**. In production add your auth middleware: `['web', 'auth']`, or e.g. `['web', 'auth', 'role:admin']` with spatie/laravel-permission.

The theme toggle in the UI is saved in the browser and overrides `theme`. Request and prompt contents are shown only when [`store_request`](../configuration.md#general) is enabled.

To customize the views:

```bash
php artisan vendor:publish --tag=ai-views
```

## Driver state

Above the filters, one row per driver (those with an API key, plus any that ran in the last 24 hours): its current state, last successful answer, last error, and runs / errors / average duration over 24 hours.

The state comes from consecutive transient failures — connection errors, timeouts, 429, 5xx — counted in the cache: `degraded` after one, `down` after three, back to `ok` on the next answer, `unknown` before any data.

A queued run that switched to a fallback driver records only the driver that answered in `ai_runs`, so this is where the failure of the first one shows up. Rejected requests (4xx) and runs with a tenant's own key ([`providerOverride`](provider-override.md)) don't count: they say nothing about the shared driver.

> [!TIP]
> Use a shared cache store (Redis) when several servers process AI tasks, otherwise each server sees only its own failures.

## Stuck runs

A run is **stuck** once it has been `queued` or `running` for longer than `stuck_after_minutes` without progress. The usual cause is a queue payload that never reached a worker (a Redis restart between `AI::queue()` writing the row and the worker picking it up), or a queue no worker consumes: nothing is left to fail the run, so it stays `queued` forever and no retry reaches it.

Stuck runs get their own stat card, a `stuck` status filter and a badge on the row. Raise the threshold above your slowest task's runtime, or long legitimate runs are flagged too.

From code: `AiRun::stuck()` scope and `$run->isStuck()`.

## Actions

Available on each row and on the run page:

- **Retry** — rebuilds the task from `ai_runs.request` and re-dispatches it, reusing the same row. Available for `error`/`dead` runs (except a failed sync attempt that a fallback driver covered) and for stuck `queued`/`running` ones; a `running` run that isn't stuck is left alone — a worker is still on it. Requires `store_request` to have been enabled when the run was recorded, otherwise there are no constructor arguments to revive.
- **Dead** — closes a run you've given up on: `status = dead`, with the reason in `error`. Fires no `AiRunFailed` event — the actual failure happened earlier and silently, and listeners shouldn't be notified about an admin's click.

The same from the CLI, including stuck runs:

```bash
php artisan ai:retry --since=24h --stuck --dry-run
```

See [`ai:retry`](../reference/commands.md#airetry).
