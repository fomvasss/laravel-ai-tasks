# Modalities

Available modalities: `text` · `image` · `embed` · `audio` · `transcription`

A task declares its modality in `modality()` and sets the same value in `AiPayload`. Everything else — routing, fallback, queues, budgets, `ai_runs` — works the same for every modality. Which providers support a modality is defined by `laravel/ai`.

| Modality | Input | `AiResponse::$content` | Model option | Default model from config |
|---|---|---|---|---|
| `text` | `messages` | text | `model` | `model` |
| `image` | first message — the prompt | base64 image | `model` | `image_model` |
| `embed` | first message — the text | JSON array of floats | `embed_model` | `embed_model` |
| `audio` | first message — the text to speak | base64 audio | `model` | `audio_model` |
| `transcription` | `options['path']` or `options['storage']` | text | `model` | provider's default |

Binary results (image, audio) are base64 strings. Decode and store them in [`onCompleted()`](queued-tasks.md#the-oncompleted-hook), not in `postprocess()` — `postprocess()` runs on every attempt, including rejected ones.

## Image generation

Providers: OpenAI, Gemini, xAI, OpenRouter, Azure OpenAI, Bedrock.

```php
class GenerateImageTask extends AiTask
{
    public function __construct(private readonly string $prompt) {}

    public function modality(): string
    {
        return 'image';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'image',
            messages: [new UserMessage($this->prompt)],
            options: [
                'size' => '1:1',      // '1:1' square, '3:2' landscape, '2:3' portrait
                'quality' => 'high',  // 'low', 'medium', 'high'
                'timeout' => 120,
            ],
        );
    }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        Storage::put('images/'.Str::uuid().'.png', base64_decode($result->content));
    }
}

$response = AI::send(new GenerateImageTask('A minimalist blue logo for a tech startup'), drivers: 'openai');
```

The aspect ratio is mapped to the provider's own size (for OpenAI `1:1` → `1024x1024`, `3:2` → `1536x1024`, `2:3` → `1024x1536`); a provider-specific value such as `1024x1024` is passed through as is. Without `timeout` the provider's default applies.

## Embeddings

Providers: OpenAI, Gemini, Mistral, Ollama, Cohere, Jina, VoyageAI, OpenRouter, Azure OpenAI, Bedrock, OpenAI-compatible.

```php
class EmbedDocumentTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string
    {
        return 'embed';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'embed',
            messages: [$this->text], // string, UserMessage or ['content' => ...]
            // options: ['embed_model' => 'text-embedding-3-large'],
        );
    }

    public function postprocess(AiResponse $resp): array
    {
        return ['vector' => json_decode($resp->content, true)];
    }
}

$response = AI::send(new EmbedDocumentTask('Your text here'), drivers: 'openai');
$vector = json_decode($response->content, true)['vector']; // see Running tasks → What send() returns
```

One task embeds one text — only the first message is used. Without a message the response is `ok: false` with error `embed_input_missing`. Note the model option is `embed_model`, not `model`.

## Text-to-speech

Providers: OpenAI, ElevenLabs, Gemini, Mistral, OpenRouter.

```php
class GenerateSpeechTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string
    {
        return 'audio';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'audio',
            messages: [$this->text],
            options: [
                'voice' => 'alloy',                           // provider's voice name
                // 'female' => true,                          // or a default female voice, when no `voice`
                'instructions' => 'Speak clearly and slowly', // optional, where supported
            ],
        );
    }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        Storage::put('audio/'.Str::uuid().'.mp3', base64_decode($result->content));
    }
}

AI::send(new GenerateSpeechTask('Hello world'), drivers: 'openai');
```

TTS returns no token usage. Cost is estimated from the input length with `price.per_char` (per 1M characters) — see [Cost tracking](costs.md).

## Transcription

Providers: OpenAI, Groq, ElevenLabs, Mistral, Gemini, OpenRouter, OpenAI-compatible.

```php
class TranscribeAudioTask extends AiTask
{
    public function __construct(private readonly string $audioPath) {}

    public function modality(): string
    {
        return 'transcription';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'transcription',
            options: [
                'path' => $this->audioPath,   // absolute file path
                // 'storage' => 'audio/a.mp3', // or a path on a filesystem disk
                // 'disk' => 's3',
                'diarize' => true,            // speaker separation, where the model supports it
            ],
        );
    }
}

$response = AI::send(new TranscribeAudioTask('/path/to/audio.mp3'), drivers: 'openai');
$response->content;                 // transcribed text
$response->usage['audio_seconds'];  // duration, when the provider reports it
```

Without `path` or `storage` the call throws `InvalidArgumentException`. Without `options['model']` the provider's default transcription model is used (for OpenAI `gpt-4o-transcribe-diarize`); there is no config key for it.

**Cost.** Duration-billed models (whisper-1, Groq, ElevenLabs, Mistral) are costed by `price.per_minute` when the provider reports the audio length. Set it per model so it doesn't apply to the driver's text model:

```php
'openai' => [
    'prices' => [
        'whisper-1' => ['per_minute' => 0.006],
    ],
    // ...
],
```

Without `per_minute` (or without a reported duration) transcription is costed by tokens like a text request — which is how gpt-4o-transcribe and Gemini bill it.
