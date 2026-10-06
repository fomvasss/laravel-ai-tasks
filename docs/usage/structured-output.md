# Structured output

Two ways to get JSON back from a text task:

- **`schema()`** — the shape is enforced by the provider itself (native structured output). Recommended.
- **`jsonMode`** — the provider guarantees valid JSON syntax; the shape is controlled by the prompt. For cases where a strict shape isn't needed, or for streaming.

`schema()` takes precedence when both are set.

## Schema

Implement `schema(): ?\Closure` on the task:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;

public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'summary' => $schema->string(),
    ];
}
```

The model can't return a shape you didn't ask for. `AiResponse::$structured` is the already-decoded array matching the schema — no `json_decode()` or markdown-fence stripping in `postprocess()`:

```php
public function postprocess(AiResponse $response): array
{
    return ['summary' => $response->structured['summary'] ?? ''];
}
```

Works with `send()` and `queue()` (the closure is wrapped in `SerializableClosure` automatically, so it survives the queue payload), but not with `stream()`.

### Field types and nesting

`JsonSchema` supports the usual field types plus nested objects and optional fields:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'action' => $schema->string()->enum(['reply', 'escalate_to_human']),
        'confidence' => $schema->number(),
        'urgent' => $schema->boolean(),
        'contact' => $schema->object([
            'name' => $schema->string(),
            'email' => $schema->string(),
        ])->nullable(), // the whole object, or null when there's nothing to report
    ];
}
```

Structured output is always a top-level **object**. A list result (e.g. keywords) has to be wrapped under a key and unwrapped in `postprocess()`:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'keywords' => $schema->array()->items($schema->string()),
    ];
}

public function postprocess(AiResponse $resp): array
{
    return ['keywords' => $resp->structured['keywords'] ?? []];
}
```

### Provider-specific options

For schema-based tasks, pass raw provider request fields via `options['provider_options']`, keyed by driver name. Only the matching driver receives its entry:

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    options: [
        'provider_options' => [
            'deepseek' => ['thinking' => ['type' => 'disabled']], // ignored by other drivers
        ],
    ],
);
```

Useful for provider-native knobs the package doesn't wrap: DeepSeek `thinking`, Anthropic extended-thinking budgets, Gemini `thinking_level`. Gemini fields must use the Interactions API names (laravel/ai 1.0).

> [!NOTE]
> `provider_options` only applies when `schema()` is used. It has no effect with `jsonMode` or plain-text tasks.

## JSON mode

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    systemPrompt: 'Classify the text. Reply with {"category": "...", "confidence": 0.0-1.0}.',
    jsonMode: true,
);
```

The package translates `jsonMode` into the provider's own parameter:

| Provider | Mechanism |
|---|---|
| OpenAI, xAI | `text.format: {type: json_object}` (Responses API) |
| Gemini | `response_format` with `mime_type: application/json` (Interactions API) |
| DeepSeek, Groq, Mistral, OpenRouter, OpenAI-compatible | `response_format: {type: json_object}` (Chat Completions) |
| Anthropic | no native JSON mode — rely on the system prompt |

> [!TIP]
> Always describe the expected JSON structure in `systemPrompt`. `jsonMode` guarantees valid syntax, not the shape. The result is in `content` as a string — decode it yourself.

## Response metadata

Besides `content` and `structured`, `AiResponse` carries fields that survive the queued round-trip (stored in `ai_runs.response`, restored for `postprocess()`):

- `$toolCalls` — tools the model actually invoked, one entry per `Laravel\Ai\Responses\Data\ToolCall::toArray()`. Empty when no tool was called.
- `$finishReason` — the last step's stop reason: `stop`, `length`, `tool_calls`, `content_filter`, `error`, `unknown`. Useful in `isAcceptable()` to tell a truncated response (`length`) apart from other failures.

`$raw` is currently always empty. Full list of fields — [AiPayload & AiResponse](../reference/payload-response.md#airesponse).
