# Driver routing

Each task is sent through a chain of drivers. The chain is resolved in this order:

1. `drivers:` argument of `AI::send()` / `AI::stream()` / `AI::queue()`
2. `viaDrivers()` on the task instance
3. `routing` in `config/ai-tasks.php`, by [task name](tasks.md#task-name)
4. `default` driver

```php
// config/ai-tasks.php
'routing' => [
    'summarize' => ['openai', 'anthropic'], // fallback chain
    'orders_analyze' => ['gemini'],
],
```

```php
AI::send((new SummarizeTask($article))->viaDrivers('gemini'));
AI::send(new SummarizeTask($article), drivers: ['gemini', 'openai']);
```

Drivers without an API key in `config/ai.php` are skipped. If none of the chain has a key, `AiDriverException` is thrown.

## Fallback

The chain is tried in order by `send()`, `stream()` and `queue()` alike. The next driver is tried when the current one fails **transiently**:

- connection error or timeout
- 429 (rate limit) and 408
- 5xx
- insufficient credits

The next driver is **not** tried when the provider rejects the request itself — other 4xx: invalid schema, context too long. The next provider would get the same request. `send()`/`stream()` throw `Fomvasss\AiTasks\Exceptions\AiDriverException` right away, with the original exception as `getPrevious()`.

When every driver fails, `send()`/`stream()` throw `AiDriverException` (`All providers failed: ...`). A queued run retries the chain from the start according to the worker's `tries`/`backoff`, see [Queued tasks](queued-tasks.md#driver-fallback-in-the-queue).

`send()` and `stream()` record every attempt as its own `ai_runs` row: a failed driver gets an `error` row (marked as superseded when a later driver answers, so it's never retried), a driver without a key a `skipped` one (`driver_not_configured`), and the driver that answered an `ok` row. A queued run is a single row; `ai_runs.driver` records the driver that answered. A failure of the first driver that the fallback covered is visible in the [driver state](dashboard.md#driver-state) on the dashboard.

Exceptions:

- `stream()` doesn't switch once output has started — the next driver would start the answer over.
- A payload with its own key (`providerOverride['key']`) uses only the first driver: the override replaces the provider for every driver in the chain. See [Per-request provider override](provider-override.md).
