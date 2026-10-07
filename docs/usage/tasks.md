# Tasks

A task is a class extending `Fomvasss\AiTasks\Tasks\AiTask`. It must implement two methods — `modality()` and `toPayload()`; everything else is optional. The full list of overridable methods is in [AiTask methods](../reference/task.md).

## Generating a task

```bash
php artisan ai:make-task SummarizeTask
php artisan ai:make-task Orders/AnalyzeTask --queued
```

Classes are created in `App\Ai\Tasks`. `--queued` adds `ShouldQueueAi`, `--force` overwrites an existing file.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use App\Models\Article;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Messages\UserMessage;

class SummarizeTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(
        private readonly Article $article,
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage("Summarize: {$this->article->body}")],
            systemPrompt: 'You are a concise summarizer. Reply in 3 sentences max.',
        );
    }

    public function schema(): ?\Closure
    {
        return fn (JsonSchema $schema): array => [
            'summary' => $schema->string(),
        ];
    }

    public function postprocess(AiResponse $response): array
    {
        // shape the raw response into your own result format — runs on every attempt,
        // including attempts a later isAcceptable() rejects, so keep this side-effect free
        return ['summary' => $response->structured['summary'] ?? ''];
    }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        // runs exactly once, only for the final result — this is where side effects belong
        $this->article->update(['summary' => $result['summary'] ?? null]);
    }
}
```

- `private readonly Article $article` — a plain Eloquent model, not an id — works because of `use SerializesModelsAi;` (added by `ai:make-task` automatically): it restores a fresh `$article` on the worker for every queued run. See [Queued tasks](queued-tasks.md).
- `schema()` guarantees the provider replies with exactly `{"summary": "..."}`, decoded into `AiResponse::$structured` — see [Structured output](structured-output.md).
- `postprocess()` shapes the response, `onCompleted()` acts on the final one — see [The `onCompleted()` hook](queued-tasks.md#the-oncompleted-hook).

## Lifecycle

```
shouldRun() → toPayload() → provider call → global pipes → postprocess()
           → isAcceptable()? → onCompleted()  /  onFailed()
```

| Method | When | Side effects |
|---|---|---|
| `shouldRun()` | Right before the provider call, queued path only | no |
| `toPayload()` | Builds the request | no |
| `postprocess()` | After every response, including rejected retry attempts | no |
| `isAcceptable()` | After `postprocess()`, only when `maxRetries() > 0` | no |
| `onCompleted()` | Once, for the final result | yes |
| `onFailed()` | Once, when the task ends without a result | yes |

For a task not skipped by `shouldRun()`, exactly one of `onCompleted()` / `onFailed()` is called.

## Task name

The name identifies the task in [routing](routing.md), the dashboard, `ai_runs.task` and `AI::fake()` responses. By default it's the class name without the `Task` suffix in snake_case: `SummarizeTask` → `summarize`, `OrdersAnalyzeTask` → `orders_analyze`.

Override `name()` or set it on an instance:

```php
AI::send((new SummarizeTask($article))->setName('article_summary'));
```

## Messages

`AiPayload::$messages` is the conversation. The last user message is the prompt, all other messages become the history:

```php
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;

messages: [
    new UserMessage('What is Laravel?'),
    new AssistantMessage('A PHP framework.'),
    new UserMessage('Which version is current?'),
],
```

Plain strings and `['role' => ..., 'content' => ...]` arrays are accepted too.

## Attachments (vision)

Pass files to a text task via `options['attachments']` — an array of `laravel/ai` file objects:

```php
use Laravel\Ai\Files\Image;

return new AiPayload(
    modality: 'text',
    messages: [new UserMessage('What is in this picture?')],
    options: ['attachments' => [Image::fromUrl($this->imageUrl)]],
);
```

Attachments are not stored in `ai_runs.request` — they are replaced with an `[N attachment(s) omitted]` placeholder.

## Tenant and subject

Override `tenantId()` to bill the run to a specific tenant, `userId()` to record who started it, and `subjectType()`/`subjectId()` to tag it with the record it concerns — see [Budgets & tenants](budgets.md).
