# Per-request provider override

`AiPayload::$providerOverride` supplies API credentials for a single task execution without touching config or `.env` — for applications that manage per-tenant or per-user API keys.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->prompt)],
    systemPrompt: $this->instructions,
    providerOverride: [
        'driver' => 'deepseek',       // any driver supported by laravel/ai
        'key' => $this->apiKey,       // user-supplied API key
        'model' => 'deepseek-flash',  // optional; overrides the driver default
        // 'url' => '...',            // optional; custom base URL
        // 'organization' => '...',   // optional; OpenAI org scoping
    ],
);
```

| Field | Type | Required | Description |
|---|---|---|---|
| `driver` | `string` | yes | Provider name (`openai`, `deepseek`, `anthropic`, …). Use `openai-compatible` for self-hosted or third-party endpoints (LM Studio, vLLM, Together, …) instead of overloading `openai` |
| `key` | `string` | yes | API key |
| `model` | `string` | no | Model name; `options['model']` takes priority, the driver default is the fallback |
| `url` | `string` | no | Custom base URL (required for `openai-compatible`) |
| `organization` | `string` | no | OpenAI organization ID |

## How it works

- A temporary provider config is registered under a deterministic alias `custom_<hash>` derived from `driver + key`. The same credentials always resolve to the same alias, so `laravel/ai`'s instance cache is reused within the process. On Octane the aliases are flushed between requests.
- `ai_runs.driver` records the readable `driver` name, not the internal alias.
- If `key` is empty or `providerOverride` is `null`, the task uses the system provider.
- A driver without a system API key is not skipped when the override supplies a key.
- Only the first driver of the [routing chain](routing.md) is used — the override replaces the provider for every driver, so a fallback would hit the same API with the same key.
- Runs with an override are not counted in the [driver state](dashboard.md#driver-state) — they say nothing about the shared provider.
