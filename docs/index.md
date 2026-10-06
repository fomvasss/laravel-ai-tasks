# Laravel AI Tasks

AI task orchestrator for Laravel. Handles routing, queuing, audit logging, budget tracking, and webhook processing on top of [laravel/ai](https://laravel.com/docs/ai-sdk) as the transport layer.

Every AI call is a task class: it builds the request, optionally shapes the response, and reacts to the final result. The package takes care of everything around the call:

- **Routing with fallback** — a chain of drivers per task; the next one is tried when a provider is down
- **Queues** — `AI::queue()` with idempotency, delayed dispatch, retries of unusable results
- **Audit log** — every run is stored in `ai_runs`: driver, model, tokens, cost, duration, status
- **Budgets** — monthly spend limit per tenant
- **Cost tracking** — per-driver and per-model rates, prompt-cache tokens, reasoning tokens
- **Dashboard** — runs list, driver state, stuck runs, retry from the browser
- **Modalities** — text, image, embeddings, text-to-speech, transcription
- **Tools & MCP**, structured output, tool approval, streaming
- **Testing** — `AI::fake()` with assertions

![Dashboard](images/dashboard.gif)

## Quick example

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

$response = AI::send(new SummarizeTask($article->body));
echo $response->content;
```

## Contents

Getting started

1. [Installation](installation.md)
2. [Configuration](configuration.md)

Usage

3. [Tasks](usage/tasks.md)
4. [Running tasks](usage/running-tasks.md)
5. [Queued tasks](usage/queued-tasks.md)
6. [Driver routing](usage/routing.md)
7. [Structured output](usage/structured-output.md)
8. [Tools & MCP](usage/tools.md)
9. [Tool choice & approval](usage/tool-approval.md)
10. [Modalities](usage/modalities.md)
11. [Budgets & tenants](usage/budgets.md)
12. [Cost tracking](usage/costs.md)
13. [Per-request provider override](usage/provider-override.md)
14. [Dashboard](usage/dashboard.md)
15. [Async providers & webhooks](usage/webhooks.md)
16. [Testing](usage/testing.md)

Reference

17. [AI facade](reference/facade.md)
18. [AiTask methods](reference/task.md)
19. [AiPayload & AiResponse](reference/payload-response.md)
20. [The ai_runs table](reference/ai-runs.md)
21. [Events](reference/events.md)
22. [Artisan commands](reference/commands.md)
23. [Providers](reference/providers.md)

[Upgrading](upgrading.md)
