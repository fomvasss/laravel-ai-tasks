# Cost tracking

Cost is calculated after each response from the driver's rates and stored in `ai_runs.cost` (USD). Without rates `cost` is `null`, but token counts are always saved.

## Rates

Per driver in `config/ai-tasks.php`, per 1M tokens:

```php
'anthropic' => [
    'model' => 'claude-sonnet-5',
    'price' => [
        'in' => 3.00,
        'out' => 15.00,
        'cache_write' => 3.75,
        'cache_read' => 0.30,
    ],
],
```

A missing `cache_read`/`cache_write` falls back to `in`, so cached tokens are never costed as free. To cost them as free, set the rate to `0` explicitly.

All price keys, including `per_char` for TTS and `per_minute` for transcription — [Configuration](../configuration.md#price-keys).

## Per-model rates

`price` is one set of rates per driver, while the model comes from `.env` — switching to a pricier model silently keeps costing the old rates. List the exceptions under `prices`, keyed by model name; anything not listed falls back to `price`:

```php
'deepseek' => [
    'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),
    'prices' => [
        'deepseek-reasoner' => ['in' => 0.55, 'out' => 2.19, 'cache_read' => 0.11],
    ],
    'price' => ['in' => 0.15, 'out' => 0.60, 'cache_read' => 0.003],
],
```

Keys match both the full model name and the part after `/`, so a gateway-prefixed `anthropic/claude-sonnet-5` also matches a `claude-sonnet-5` entry.

## Rates snapshot

The rates used are stored with the run in `ai_runs.cost_rates`:

```json
{"model": "deepseek-flash", "source": "driver", "in": 0.15, "out": 0.6, "cache_read": 0.003}
```

`source` is `model:<name>` when the rate came from `prices`, `driver` when from `price`. `cost` is computed from config at run time, so without the snapshot a row written before a price change or a model switch can't be explained afterwards. It also makes drift detectable: recompute a period from tokens at today's rates and compare with stored `cost`.

The column comes from a migration added in 3.27.0 — after upgrading run `vendor:publish --tag=ai-migrations` and `migrate`. `php artisan about` shows whether the schema is up to date; until then runs are stored without the snapshot.

## Tokens

| Column | Contains |
|---|---|
| `tokens_in` | Input tokens billed at full price only — cached ones are never included |
| `tokens_out` | Output tokens, reasoning included (billed at the output rate) |
| `cache_read_tokens` | Input read from the provider's prompt cache |
| `cache_write_tokens` | Input written to the prompt cache |

The meaning is the same for every driver.

## Prompt caching

Providers that cache automatically (OpenAI, Gemini implicit cache, DeepSeek, Mistral) report cached tokens, and they are costed at the `cache_read`/`cache_write` rates.

> [!NOTE]
> Anthropic's explicit cache breakpoints (`cache_control`) are not exposed by the package yet. The `'cache' => true` payload option shown in older READMEs has no effect.

## Querying spend

```php
use Fomvasss\AiTasks\Models\AiRun;

AiRun::where('tenant_id', $tenantId)
    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
    ->whereNotNull('cost')
    ->sum('cost');
```

Filter by `cost IS NOT NULL`, not `status = 'ok'`: a run rejected by the post-call budget check was billed by the provider but has status `error`. This is how budgets count spend too.

## Known approximations

- **DeepSeek** doubles its rates during peak hours; cost has no time-of-day tiering, so it's understated for calls in those windows.
- **TTS** (`per_char`) is an estimate from the input length — the provider returns no usage.
