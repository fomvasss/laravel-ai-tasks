# Testing

`AI::fake()` swaps the facade for a fake that makes no API calls, records every call and provides assertions.

```php
use Fomvasss\AiTasks\Facades\AI;

// Default: every task returns "fake ai response"
$fake = AI::fake();

// Fixed response for all tasks
$fake = AI::fake('Short summary.');

// Per-task responses, matched by task name
$fake = AI::fake([
    'summarize' => 'This is a summary.',
    'translate' => 'Це переклад.',
    '*' => 'Default fallback.', // catch-all
]);
```

## What the fake does

| Call | Behaviour |
|---|---|
| `send()`, `prompt()` | Runs `postprocess()`, `onCompleted()` and fires `AiTaskCompleted`, like the real pipeline |
| `stream()` | The same, and calls `$onChunk` once with the full response |
| `queue()` | Only records the call and returns a fake run id; the task doesn't run |

A task without its own entry gets `*`, and without `*` the text `fake ai response`.

The response has `content` set to the fake text, zero tokens and cost, and `structured = null` — a task relying on `schema()` gets no structured data from the fake. No tools are invoked and nothing is written to `ai_runs`. `AI::models()` is not available on the fake.

To test `postprocess()` with structured data, call it directly:

```php
$result = (new SummarizeTask($article))->postprocess(
    new AiResponse(ok: true, structured: ['summary' => 'Short.']),
);
```

`queue()` applies the same guard as the real dispatcher: a task with required constructor parameters and an empty `serializeForQueue()` throws `LogicException`.

## Assertions

```php
$fake->assertSent(SummarizeTask::class);

$fake->assertSent(SummarizeTask::class, function (AiTask $task, string $method) {
    return $task->name() === 'summarize' && $method === 'send';
});

$fake->assertNotSent(TranslateTask::class);

$fake->assertQueued(SummarizeTask::class);
$fake->assertQueued(SummarizeTask::class, fn (AiTask $task) => $task->modality() === 'text');

$fake->assertSentCount(3); // total calls: send + stream + queue

$fake->assertNothingSent();
```

`assertSent()` matches any method (`send`, `stream`, `queue`), `assertQueued()` only `queue`. `$fake->recorded()` returns the raw list: `['method' => ..., 'task' => ..., 'drivers' => [...]]`.

## Null driver

For local development without API keys, route tasks to the pre-configured `null` driver — it returns an empty response and records the run as usual:

```env
AI_DEFAULT=null
```
