# Provider quirks

Behaviour of specific providers and models that cost time to diagnose. Provider behaviour changes over time — treat this as a list of things to check, and re-run your [evaluation](testing-in-practice.md#evaluation-harness) after any model switch.

## Blank replies from reasoning models

**Symptom:** `ok: true`, `finish_reason: stop`, but `content` is empty or whitespace; the output tokens are still billed. With `maxRetries(1)` the retry often fails the same way and the chat is escalated.

**Cause:** the model spent its output on hidden reasoning. Seen with DeepSeek V4 Flash, where reasoning ("thinking") is on by default and combined with JSON output returned blank content in a quarter of replies; lowering the reasoning effort reduced it but didn't remove it.

**Fix:** turn reasoning off for tasks that don't need it, through `provider_options`:

```php
options: [
    'provider_options' => [
        'deepseek' => ['thinking' => ['type' => 'disabled']],
    ],
],
```

Measured on a chat assistant: blank replies dropped to zero, the median latency went down and the cost too.

`provider_options` is keyed by driver name, and only the entry of the driver that actually runs is sent. **A task that sets options only for `openai` sends nothing to `deepseek`, without any warning** — add an entry for every driver in the task's chain. Verify what goes over the wire with `Http::fake()`, see [Testing in practice](testing-in-practice.md#3-asserting-the-provider-request).

## Structured output on non-strict providers

**Symptom:** `AiResponse::$structured` is an empty array; `content` holds prose, JSON in markdown fences, or JSON mixed with text.

**Cause:** OpenAI, Anthropic and Gemini enforce the JSON schema. DeepSeek and most OpenAI-compatible providers only support JSON mode (`json_object`) — the schema reaches them as text, and when the reply doesn't decode, `structured` is empty without an error.

**Fix:**

- a fallback parser in `postprocess()` for `content` — see [Normalize in postprocess()](chat-assistant.md#normalize-in-postprocess)
- a "Key rules" block in the system prompt that repeats the schema
- past assistant turns rendered in the schema's JSON shape in the history — the strongest lever; shortening the history did not help

## Sampling parameters on reasoning models

OpenAI reasoning models (`gpt-5*`, `o*`) and the newest Claude models reject `temperature` / `top_p` with a 400. The package drops them for the known models (see [Generation options](../usage/running-tasks.md#generation-options)); for a model it doesn't know yet, leave `temperature` out of the task.

## Forced tool choice in reasoning mode

Some providers reject `tool_choice: required` while reasoning is on. Catch the 400 and retry without the forced choice, relying on the prompt instruction.

## Invented ids

Models occasionally return an id that wasn't among the options — even with an enum in the schema. Validate every returned id against the list you sent.

## Hanging requests

A provider can accept the connection and send nothing for the whole HTTP timeout. With the default 60 seconds, three such attempts take a queued job past its timeout. Lower `options['timeout']` for interactive tasks and configure a [fallback chain](production.md#failover).

## Pricing details

- **DeepSeek** charges double during peak hours; the package costs every call at one rate. Its legacy model names still answer but aren't reported as supported — list both the old and the new name under `prices`.
- **OpenAI** bills cache writes at 1.25× and cache reads at 0.1× of the input rate from GPT-5.6 on. A price without `cache_write`/`cache_read` falls back to the input rate.
- **Anthropic** extended thinking is billed as output, so `tokens_out` of such runs is higher than the visible answer.

## Upgrading laravel/ai

The 1.0 upgrade needed, besides the package's own notes in [Upgrading](../upgrading.md#328--laravelai-10): Gemini `provider_options` renamed to the Interactions API fields, `aws/aws-sdk-php` installed separately for Bedrock, `laravel/mcp` ^1.0 — and in one application a newer error-tracking SDK release that understood the new token usage objects.
