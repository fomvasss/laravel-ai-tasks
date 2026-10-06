# AI facade

`Fomvasss\AiTasks\Facades\AI`, alias `AI`. Resolves `Fomvasss\AiTasks\Core\AI`.

| Method | Returns | Description |
|---|---|---|
| `send(AiTask $task, array\|string $drivers = [])` | `AiResponse` | Sync call, see [Running tasks](../usage/running-tasks.md) |
| `stream(AiTask $task, callable $onChunk, array\|string $drivers = [])` | `AiResponse` | Sync call with chunks to `$onChunk(string $chunk)` |
| `queue(AiTask $task, array\|string $drivers = [], DateTimeInterface\|DateInterval\|int\|null $delay = null)` | `string` | Queue the task, returns the `ai_runs` id, see [Queued tasks](../usage/queued-tasks.md) |
| `prompt(string $prompt, ?string $system = null, array\|string $drivers = [], string $name = 'prompt')` | `AiResponse` | One-off call without a task class |
| `models(string $driver, ?string $filter = null)` | `array` | Models from the provider's API, see below |
| `fake(string\|array\|null $responses = null)` | `FakeAI` | Swap for a fake, see [Testing](../usage/testing.md) |

`$drivers` overrides the routing chain: a driver name or a list.

## Exceptions

| Exception | When |
|---|---|
| `Fomvasss\AiTasks\Exceptions\AiDriverException` | No configured driver, every driver failed, the provider rejected the request, or a stream broke off |
| `Fomvasss\AiTasks\Exceptions\BudgetExceededException` | Tenant's monthly budget exceeded, see [Budgets](../usage/budgets.md) |
| `LogicException` | `queue()` for a task that can't be rebuilt on the worker |

## Listing models

```php
$models = AI::models('openai', filter: 'gpt');
// [['id' => 'gpt-5.6-luna', 'display_name' => null, 'owner' => 'system', 'created' => '2026-06-23', ...], ...]
```

Credentials are taken from `config/ai.php`. Throws:

- `AiDriverException` — the driver has no API key
- `ModelListingUnavailableException` — the provider has no listing endpoint
- `ModelListingException` — connection or API error

With credentials at hand, skip the config lookup and use `ModelLister` directly:

```php
use Fomvasss\AiTasks\Support\ModelLister;

$models = app(ModelLister::class)->forDriver('openai', ['api_key' => $key], filter: 'gpt');
```

The CLI equivalent is [`ai:models`](commands.md#aimodels).
