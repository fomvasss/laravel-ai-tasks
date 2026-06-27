<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Cost;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;

final class LaravelAiDriver implements AiDriver
{
    public function __construct(
        private readonly string $provider,
        private readonly array  $cfg,
    ) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text', 'embed', 'image', 'audio', 'transcription'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        return match ($p->modality) {
            'embed'         => $this->sendEmbed($p),
            'image'         => $this->sendImage($p),
            'audio'         => $this->sendAudio($p),
            'transcription' => $this->sendTranscription($p),
            default         => $this->sendText($p),
        };
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        return $this->streamText($p, $onChunk);
    }

    // ── Text ──────────────────────────────────────────────────────────────

    private function sendText(AiPayload $p): AiResponse
    {
        $model = $p->options['model'] ?? $this->cfg['model'];
        [$history, $prompt] = $this->splitMessages($p->messages);

        $agent = $p->jsonMode
            ? new JsonModeAgent($p->systemPrompt ?? '', $history, $p->tools)
            : new AnonymousAgent($p->systemPrompt ?? '', $history, $p->tools);

        $response = $agent->prompt(
            prompt: $prompt,
            attachments: $p->options['attachments'] ?? [],
            provider: $this->provider,
            model: $model,
            timeout: $p->options['timeout'] ?? 60,
        );

        $usage         = $this->mapUsage($response->usage);
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(ok: true, content: $response->text, usage: $usage);
    }

    private function streamText(AiPayload $p, callable $onChunk): AiResponse
    {
        $model = $p->options['model'] ?? $this->cfg['model'];
        [$history, $prompt] = $this->splitMessages($p->messages);

        $streamable = (new AnonymousAgent($p->systemPrompt ?? '', $history, $p->tools))
            ->stream(
                prompt: $prompt,
                attachments: $p->options['attachments'] ?? [],
                provider: $this->provider,
                model: $model,
                timeout: $p->options['timeout'] ?? null,
            );

        $text  = '';
        $usage = [];

        $streamable->then(function ($streamed) use (&$text, &$usage) {
            $text          = $streamed->text ?? '';
            $usage         = $this->mapUsage($streamed->usage);
            $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);
        });

        foreach ($streamable as $event) {
            if ($event instanceof TextDelta && $event->delta !== '') {
                $onChunk($event->delta);
            }
        }

        return new AiResponse(true, $text, $usage);
    }

    // ── Embeddings ────────────────────────────────────────────────────────

    private function sendEmbed(AiPayload $p): AiResponse
    {
        $model = $p->options['embed_model'] ?? $this->cfg['embed_model'] ?? null;
        $input = $p->messages[0] ?? null;

        if ($input === null) {
            return new AiResponse(false, null, [], [], 'embed_input_missing');
        }

        $response = Embeddings::for([$this->messageToText($input)])
            ->generate($this->provider, $model);

        $usage         = ['driver' => $this->provider, 'tokens_in' => $response->tokens ?: null];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(ok: true, content: json_encode($response->first()), usage: $usage);
    }

    // ── Image ─────────────────────────────────────────────────────────────

    private function sendImage(AiPayload $p): AiResponse
    {
        $model   = $p->options['model'] ?? $this->cfg['image_model'] ?? null;
        $prompt  = $this->messageToText($p->messages[0] ?? '');
        $pending = Image::of($prompt);

        if ($quality = $p->options['quality'] ?? null) {
            $pending->quality($quality);
        }
        if (($p->options['size'] ?? null) === '3:2') {
            $pending->landscape();
        } elseif (($p->options['size'] ?? null) === '2:3') {
            $pending->portrait();
        }
        if ($timeout = $p->options['timeout'] ?? null) {
            $pending->timeout($timeout);
        }

        $response      = $pending->generate($this->provider, $model);
        $image         = $response->firstImage();
        $usage         = $this->mapUsage($response->usage);
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $image->image, $usage);
    }

    // ── Audio (TTS) ───────────────────────────────────────────────────────

    private function sendAudio(AiPayload $p): AiResponse
    {
        $model   = $p->options['model'] ?? $this->cfg['audio_model'] ?? null;
        $text    = $this->messageToText($p->messages[0] ?? '');
        $pending = Audio::of($text);

        if ($voice = $p->options['voice'] ?? null) {
            $pending->voice($voice);
        } elseif ($p->options['female'] ?? false) {
            $pending->female();
        }
        if ($instructions = $p->options['instructions'] ?? null) {
            $pending->instructions($instructions);
        }

        $response = $pending->generate($this->provider, $model);

        return new AiResponse(true, $response->audio, ['driver' => $this->provider]);
    }

    // ── Transcription (STT) ───────────────────────────────────────────────

    private function sendTranscription(AiPayload $p): AiResponse
    {
        $model   = $p->options['model'] ?? null;
        $path    = $p->options['path'] ?? null;
        $storage = $p->options['storage'] ?? null;
        $disk    = $p->options['disk'] ?? null;

        $pending = match (true) {
            $path !== null    => Transcription::fromPath($path),
            $storage !== null => Transcription::fromStorage($storage, $disk),
            default           => throw new \InvalidArgumentException('Transcription requires options.path or options.storage'),
        };

        if ($p->options['diarize'] ?? false) {
            $pending->diarize();
        }

        $response      = $pending->generate($this->provider, $model);
        $usage         = $this->mapUsage($response->usage);
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        return new AiResponse(true, $response->text, $usage);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function splitMessages(array $messages): array
    {
        if (empty($messages)) {
            return [[], ''];
        }

        $labMessages = array_map(fn ($m) => $this->toLabMessage($m), $messages);

        // Find the last user message to use as the prompt
        $lastUserIdx = null;
        for ($i = count($labMessages) - 1; $i >= 0; $i--) {
            if ($labMessages[$i]->role->value === 'user') {
                $lastUserIdx = $i;
                break;
            }
        }

        if ($lastUserIdx === null) {
            return [$labMessages, ''];
        }

        $prompt  = $labMessages[$lastUserIdx]->content ?? '';
        $history = [
            ...array_slice($labMessages, 0, $lastUserIdx),
            ...array_slice($labMessages, $lastUserIdx + 1),
        ];

        return [$history, $prompt];
    }

    private function toLabMessage(mixed $m): Message
    {
        if ($m instanceof Message) {
            return $m;
        }

        // Handle old Prism message objects (backwards compat for existing tasks)
        if (is_object($m) && property_exists($m, 'content')) {
            $isAssistant = str_contains(get_class($m), 'AssistantMessage');
            return $isAssistant
                ? new AssistantMessage($m->content ?? '')
                : new UserMessage($m->content ?? '');
        }

        if (is_array($m)) {
            return new Message($m['role'] ?? 'user', $m['content'] ?? '');
        }

        return new UserMessage((string) $m);
    }

    private function messageToText(mixed $m): string
    {
        if (is_string($m)) return $m;
        if (is_object($m) && property_exists($m, 'content')) return (string) $m->content;
        if (is_array($m)) return (string) ($m['content'] ?? '');
        return '';
    }

    private function mapUsage(?Usage $usage): array
    {
        if ($usage === null) {
            return ['driver' => $this->provider];
        }

        return [
            'driver'             => $this->provider,
            'tokens_in'          => $usage->promptTokens ?: null,
            'tokens_out'         => $usage->completionTokens ?: null,
            'cache_read_tokens'  => $usage->cacheReadInputTokens ?: null,
            'cache_write_tokens' => $usage->cacheWriteInputTokens ?: null,
        ];
    }
}
