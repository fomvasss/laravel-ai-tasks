# Modalities

[← Back to README](../README.md)

Available modalities: `text` · `image` · `embed` · `audio` · `transcription`

---

## Image Generation

Set `modality: 'image'` in the payload. Supported via OpenAI (`gpt-image-2`, `dall-e-3`) and Gemini.

```php
class GenerateImageTask extends AiTask
{
    public function modality(): string { return 'image'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'image',
            messages: [new UserMessage('A minimalist blue logo for a tech startup')],
            options: [
                'model'   => 'gpt-image-2',
                'size'    => '1024x1024', // or '3:2' landscape / '2:3' portrait
                'quality' => 'standard',
                'timeout' => 120,
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — base64 string (image/png)
        if ($resp->ok) {
            $path = storage_path('app/images/generated_' . time() . '.png');
            file_put_contents($path, base64_decode($resp->content));
        }
        return $resp;
    }
}

$r = AI::send(new GenerateImageTask(), drivers: ['openai']);
// $r->content — base64 PNG image
```

---

## Embeddings

Convert text to vector embeddings for semantic search, clustering, etc.

```php
class EmbedDocumentTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string { return 'embed'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'embed',
            messages: [$this->text], // string, array, or UserMessage
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — JSON array of floats (embedding vector)
        $vector = json_decode($resp->content, true);
        return [
            'ok'     => $resp->ok,
            'dims'   => count($vector),
            'vector' => $vector,
            'tokens' => $resp->usage['tokens_in'] ?? null,
        ];
    }
}

$r = AI::send(new EmbedDocumentTask('Your text here'), drivers: ['openai']);
// Returns: { "ok": true, "dims": 1536, "vector": [0.023, -0.012, ...] }
```

Supported models:
- OpenAI: `text-embedding-3-small`, `text-embedding-3-large`
- Gemini: `gemini-embedding-001`

---

## Audio & Text-to-Speech

Generate speech from text via OpenAI or ElevenLabs.

```php
class GenerateSpeechTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string { return 'audio'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'audio',
            messages: [$this->text],
            options: [
                'model'        => 'gpt-4o-mini-tts', // OpenAI's current default TTS model
                'voice'        => 'alloy', // alloy, echo, fable, onyx, nova, shimmer
                'female'       => false,   // or true for ElevenLabs
                'instructions' => 'Speak clearly and slowly', // optional
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — base64 audio (MP3 or WAV)
        if ($resp->ok) {
            $path = storage_path('app/audio/speech_' . time() . '.mp3');
            file_put_contents($path, base64_decode($resp->content));
        }
        return ['ok' => $resp->ok, 'size' => strlen($resp->content)];
    }
}

AI::send(new GenerateSpeechTask('Hello world'), drivers: ['openai']);
```

---

## Transcription & Speech-to-Text

Convert audio files to text via OpenAI, ElevenLabs, Mistral, or Gemini.

```php
class TranscribeAudioTask extends AiTask
{
    public function __construct(private readonly string $audioPath) {}

    public function modality(): string { return 'transcription'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'transcription',
            options: [
                'path'    => $this->audioPath, // full file path
                // or use storage disk:
                // 'storage' => 'file_path',
                // 'disk'    => 'local',
                'diarize' => true, // speaker identification (OpenAI only)
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        return [
            'ok'               => $resp->ok,
            'text'             => $resp->content,
            'duration_seconds' => round(strlen($resp->content) / 100),
        ];
    }
}

$r = AI::send(new TranscribeAudioTask('/path/to/audio.mp3'), drivers: ['openai']);
// Returns: { "ok": true, "text": "transcribed text...", "duration_seconds": 42 }
```

Supported formats: MP3, MP4, MPEG, MPGA, M4A, OGG, WAV, WEBM
