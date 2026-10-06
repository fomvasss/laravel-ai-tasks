# Providers

Any provider supported by [laravel/ai](https://laravel.com/docs/ai-sdk) works — add a section to `config/ai.php` (credentials) and `config/ai-tasks.php` (model, price). No code changes needed.

Pre-configured in `config/ai-tasks.php` — only the `.env` key is needed:

| Provider | Driver key | Pre-configured |
|---|---|---|
| OpenAI | `openai` | yes |
| Anthropic | `anthropic` | yes |
| Google Gemini | `gemini` | yes |
| DeepSeek | `deepseek` | yes |
| Groq | `groq` | yes |
| Mistral | `mistral` | yes |
| xAI (Grok) | `xai` | yes |
| Ollama (local) | `ollama` | yes |
| OpenRouter | `openrouter` | yes |
| ElevenLabs | `eleven` | yes, audio/TTS only |
| Null (no calls) | `null` | yes |
| AWS Bedrock | `bedrock` | add manually, plus `composer require aws/aws-sdk-php` |
| Azure OpenAI, Cohere, Jina, VoyageAI, OpenAI-compatible, … | see `laravel/ai` | add manually |

Prices are pre-filled for OpenAI, Anthropic, Gemini and DeepSeek; for the others `price` is `null` and cost isn't tracked until you set it. Default models and rates change over time — check them against the provider before relying on cost numbers.

## How credentials work

`laravel/ai` reads API keys from `config/ai.php`. The key is **not** stored in `config/ai-tasks.php` — that file only holds models, prices and routing. A driver counts as configured when `ai.providers.{driver}.key` is set (`access_key_id` for Bedrock).

The `.env` variable each provider expects is in `vendor/laravel/ai/config/ai.php`.

> [!NOTE]
> Local Ollama needs no key, but a driver without one is skipped. Set `OLLAMA_API_KEY` to any non-empty value.

## Adding a provider

```php
// 1. config/ai.php (laravel/ai) — most providers are already listed there
'voyageai' => [
    'driver' => 'voyageai',
    'key' => env('VOYAGEAI_API_KEY'),
],

// 2. config/ai-tasks.php → drivers; the key must match the one in config/ai.php
'voyageai' => [
    'embed_model' => '<model name>',
    'price' => ['in' => 0.00], // per 1M tokens, from the provider's pricing page
],
```

Which modalities a provider supports is defined by `laravel/ai` (VoyageAI, for instance, does embeddings only). Then route tasks to it — see [Driver routing](../usage/routing.md).

For per-user keys or a self-hosted OpenAI-compatible endpoint without touching config, see [Per-request provider override](../usage/provider-override.md).
