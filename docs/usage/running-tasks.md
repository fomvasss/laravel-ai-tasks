# Running tasks

```php
use Fomvasss\AiTasks\Facades\AI;

// Sync
$response = AI::send(new SummarizeTask($article));
echo $response->content;

// Async (queue) — returns the ai_runs id
$runId = AI::queue(new SummarizeTask($article));

// Streaming
$response = AI::stream(new SummarizeTask($article), function (string $chunk) {
    echo $chunk;
});

// Override the driver chain at runtime
$response = AI::send(new SummarizeTask($article), drivers: 'anthropic');
$response = AI::send(new SummarizeTask($article), drivers: ['anthropic', 'openai']);
```

`send()` and `stream()` return an [`AiResponse`](../reference/payload-response.md#airesponse). `queue()` returns the run id; the result arrives later in `onCompleted()` or the `AiTaskCompleted` event — see [Queued tasks](queued-tasks.md).

## Quick prompts

For a one-off call that doesn't warrant a dedicated task class, use `AI::prompt()`. It still goes through `send()` — routing, budget checks and `ai_runs` tracking apply as usual:

```php
$response = AI::prompt('How are you?');
echo $response->content;

// With a system prompt, explicit driver, and a custom dashboard/routing name
$response = AI::prompt(
    prompt: 'Summarize this in one sentence: ...',
    system: 'You are a terse assistant.',
    drivers: 'anthropic',
    name: 'quick_summary',
);
```

Runs default to the `prompt` task name unless `name` is given. For anything reused, queued, or needing `postprocess()`/`schema()`/`tools()`, write a proper task instead.

The same from the CLI — [`ai:request`](../reference/commands.md#airequest).

## Streaming

`AI::stream()` delivers the response text chunk by chunk via a callback — for real-time UI (SSE, WebSockets):

```php
$response = AI::stream(
    new SummarizeTask($article),
    function (string $chunk) {
        echo $chunk; // or: broadcast(new ChunkReceived($chunk))
    },
    drivers: ['openai'],
);

// After the stream ends:
$response->content; // full accumulated text
$response->usage;   // tokens + cost, same as AI::send()
```

Every provider supported by `laravel/ai` streams — OpenAI, Anthropic, Gemini, DeepSeek, Groq, Mistral, xAI, Ollama, and OpenAI-compatible endpoints.

Streaming supports `jsonMode` and `toolChoice`, but not `schema()` and not resuming with `decisions`. Once output has started, `stream()` doesn't switch to the next driver on failure — it would start the answer over after text the caller already received; it throws `AiDriverException` instead.

## Generation options

For text tasks, `temperature`, `max_tokens` and `top_p` in `AiPayload` options are passed to the provider (any of them may be omitted):

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    options: ['temperature' => 0.3, 'max_tokens' => 1024, 'top_p' => 0.9],
);
```

`temperature` and `top_p` are dropped from the request when the target model rejects them outright with a 400 — OpenAI's reasoning models (`gpt-5*` except `gpt-5-chat`, `o1`, `o3`, `o4-mini`) and the Claude models where Anthropic removed sampling parameters (`claude-fable-5`, `claude-mythos-5`, `claude-opus-5`, `claude-opus-4-8`, `claude-opus-4-7`, `claude-sonnet-5`, also behind a gateway prefix such as `anthropic/claude-sonnet-5`). A task declaring `temperature` stays portable: the option is ignored on those models instead of failing the run. `max_tokens` is always passed through.

Other options:

| Option | Description |
|---|---|
| `model` | Model for this request instead of the driver's default |
| `timeout` | HTTP timeout in seconds, default `60` |
| `attachments` | Files for vision, see [Tasks](tasks.md#attachments-vision) |
| `provider_options` | Raw provider-specific fields, see [Structured output](structured-output.md#provider-specific-options) |

Modality-specific options (`size`, `quality`, `voice`, …) — [Modalities](modalities.md).

## Long responses

`send()` and `stream()` both time out after 60 seconds by default. For large outputs (long articles, detailed reports) raise `timeout` in the payload options:

```php
options: ['timeout' => 300],
```

For a queued task also raise [`jobTimeout()`](queued-tasks.md#job-timeout), otherwise the worker kills the job first.
