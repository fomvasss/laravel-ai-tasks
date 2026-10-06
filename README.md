# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

AI task orchestrator for Laravel. Handles routing, queuing, audit logging, budget tracking, and webhook processing on top of [laravel/ai](https://laravel.com/docs/ai-sdk) as the transport layer.

[Українською](README.uk.md)

![Dashboard](docs/images/dashboard.gif)

- **Routing with fallback** — a chain of drivers per task, the next one is tried when a provider is down
- **Queues** — idempotency, delayed dispatch, retries of unusable results, `onCompleted()`/`onFailed()` hooks
- **Audit log** — every run in `ai_runs`: driver, model, tokens, cost, duration, status
- **Budgets and cost tracking** — per tenant, per driver and per model
- **Dashboard** — runs, driver state, stuck runs, retry from the browser
- **Text, image, embeddings, TTS, transcription**, tools & MCP, structured output, streaming
- **`AI::fake()`** for tests

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13
- laravel/ai ^1.0

## Installation

```bash
composer require fomvasss/laravel-ai-tasks

php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --tag=ai-tasks-config
php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

```env
AI_DEFAULT=openai
OPENAI_API_KEY=sk-...
```

> **Security:** the dashboard at `/ai-tasks` is open to anyone by default. Set `dashboard.middleware` to e.g. `['web', 'auth']` before deploying.

## Quick start

```bash
php artisan ai:make-task SummarizeTask
```

```php
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;

class SummarizeTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage("Summarize: {$this->text}")],
            systemPrompt: 'Reply in 3 sentences max.',
        );
    }
}
```

```php
use Fomvasss\AiTasks\Facades\AI;

$response = AI::send(new SummarizeTask($text));   // sync
$runId = AI::queue(new SummarizeTask($text));     // queue
AI::stream(new SummarizeTask($text), fn (string $chunk) => print($chunk));
```

## Documentation

- [Installation](docs/installation.md) · [Configuration](docs/configuration.md)
- [Tasks](docs/usage/tasks.md) · [Running tasks](docs/usage/running-tasks.md) · [Queued tasks](docs/usage/queued-tasks.md) · [Driver routing](docs/usage/routing.md)
- [Structured output](docs/usage/structured-output.md) · [Tools & MCP](docs/usage/tools.md) · [Tool choice & approval](docs/usage/tool-approval.md) · [Modalities](docs/usage/modalities.md)
- [Budgets & tenants](docs/usage/budgets.md) · [Cost tracking](docs/usage/costs.md) · [Provider override](docs/usage/provider-override.md)
- [Dashboard](docs/usage/dashboard.md) · [Webhooks](docs/usage/webhooks.md) · [Testing](docs/usage/testing.md)
- Reference: [AI facade](docs/reference/facade.md) · [AiTask](docs/reference/task.md) · [AiPayload & AiResponse](docs/reference/payload-response.md) · [ai_runs](docs/reference/ai-runs.md) · [Events](docs/reference/events.md) · [Commands](docs/reference/commands.md) · [Providers](docs/reference/providers.md)
- [Upgrading](docs/upgrading.md) · [Changelog](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE.md).

## Support

If this package is useful to you, consider supporting its development:

[![Monobank](https://img.shields.io/badge/Donate-Monobank-black)](https://send.monobank.ua/jar/5xsqtHvVrY)
[![Ko-Fi](https://img.shields.io/badge/Donate-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/fomvasss)
[![USDT TRC20](https://img.shields.io/badge/Donate-USDT%20TRC20-26A17B?logo=tether&logoColor=white)](https://link.trustwallet.com/send?coin=195&address=THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf&token_id=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t)

> USDT TRC20: `THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf`
