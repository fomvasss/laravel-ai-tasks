# Testing in practice

`AI::fake()` covers dispatch logic, but not structured output or what goes over the wire. A combination of four levels worked best. API reference: [Testing](../usage/testing.md).

## 1. Dispatch with AI::fake()

Whether a task is queued at all, with which arguments, and that nothing is sent when AI is off:

```php
AI::fake();

$this->postJson('/api/messages', ['text' => 'Hi'])->assertCreated();

AI::assertQueued(ChatReplyTask::class, fn (ChatReplyTask $task) => $task->name() === 'chat_reply');
```

```php
config(['services.ai.enabled' => false]);
AI::fake();

// ...

AI::assertNothingSent();
```

A bare `AI::fake()` also works as a tripwire: if a code path that should never call the AI does, the fake's plain-text answer won't parse and the test fails.

Keep a global kill switch off in `.env.testing` and call `AI::fake()` in tests that touch features queuing AI tasks — then no test or seeder reaches a provider by accident.

## 2. Hooks called directly

`postprocess()`, `isAcceptable()`, `shouldRun()`, `onCompleted()` and `onFailed()` are plain methods — the most valuable tests call them without the package pipeline:

```php
$task = new ChatReplyTask($message);

$result = $task->postprocess(new AiResponse(ok: true, structured: [
    'message' => 'Hello!',
    'action' => 'reply',
]));
$this->assertTrue($task->isAcceptable($result));

// a non-strict provider answering with fenced JSON
$result = $task->postprocess(new AiResponse(ok: true, content: "```json\n{\"message\":\"Hi\"}\n```"));
$this->assertSame('Hi', $result['message']);

$task->onCompleted($result, attemptsExhausted: true);
$this->assertTrue($message->chat->fresh()->isHandedOver());

$task->onFailed(new \RuntimeException('provider down'));
```

This is the only way to test structured output: the fake never fills `structured`. It also covers hallucinated ids, legitimate negative results and the superseded checks.

Check the payload the same way — `$task->toPayload()->options`, `->messages`, `->decisions`.

**Mind cached relations.** On the worker a task rebuilt by `SerializesModelsAi` has a fresh model without loaded relations; in a test the same instance may carry stale ones. `unsetRelation()` or `fresh()` before calling the hooks.

## 3. Asserting the provider request

To check what actually goes over the wire — provider options, JSON mode, the absence of a parameter — fake the provider's HTTP endpoint instead of the facade:

```php
config([
    'ai.providers.deepseek.key' => 'test-key',
    'ai-tasks.routing.chat_reply' => ['deepseek'],
]);

Http::fake([
    'api.deepseek.com/*' => Http::response([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => json_encode(['message' => 'Hi', 'action' => 'reply'])],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]),
]);

AI::send(new ChatReplyTask($message), 'deepseek');

Http::assertSent(fn ($request) => $request['thinking'] === ['type' => 'disabled']
    && $request['response_format'] === ['type' => 'json_object']);
```

This catches `provider_options` set for one driver only, and runs the real sync path including the cost calculation.

## Evaluation harness

Unit tests don't show how a model behaves. Keep an artisan command that runs a set of scenarios against a real provider and prints the share of bad answers:

```bash
php artisan ai:eval {scenarios} --driver=deepseek --model=deepseek-flash --runs=10
```

- scenarios in a JSON file: a conversation and the expectations (`action`, `locale`, extracted contact fields)
- run each several times — the failures worth knowing about are the 1-in-5 ones
- report: blank replies, replies that aren't valid JSON, unexpected actions, median latency, cost (read from `ai_runs`)
- an eval subclass of the production task, so the prompt is exactly the production one, with `postprocess()` keeping the raw content for the report

Run it before changing the model, the prompt, the schema, the history format, or the version of this package or `laravel/ai`. Each measured change in [Provider quirks](provider-quirks.md) came from such a run.
