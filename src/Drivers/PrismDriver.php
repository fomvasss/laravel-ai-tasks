<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Support\Cost;

final class PrismDriver implements AiDriver
{
    public function __construct(
        private readonly string $provider,
        private readonly array  $cfg,
    ) {}

    public function supports(string $modality): bool
    {
        return in_array($modality, ['text', 'embed'], true);
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], "driver_not_configured: {$this->provider}");
        }

        return match ($p->modality) {
            'embed' => $this->sendEmbed($p),
            default => $this->sendText($p),
        };
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        if (empty($this->cfg['api_key'])) {
            return new AiResponse(false, null, [], [], "driver_not_configured: {$this->provider}");
        }

        $model = $p->options['model'] ?? $this->cfg['model'];

        $prism = Prism::text()
            ->using($this->provider, $model)
            ->withMessages($p->messages)
            ->usingTemperature($p->options['temperature'] ?? 0.3);

        if ($p->systemPrompt) {
            $prism = $prism->withSystemPrompt($p->systemPrompt);
        }

        $fullText = '';

        try {
            foreach ($prism->asStream() as $chunk) {
                $delta = $chunk->text ?? '';
                if ($delta !== '') {
                    $fullText .= $delta;
                    $onChunk($delta);
                }
            }

            return new AiResponse(true, $fullText, $this->mapUsage(null));
        } catch (\Throwable) {
            // fallback to non-streaming (e.g. Prism 0.70 OpenAI stream bug)
            $result = $prism->asText();
            $onChunk($result->text);

            $usage        = $this->mapUsage($result->usage);
            $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

            return new AiResponse(true, $result->text, $usage);
        }
    }

    private function sendText(AiPayload $p): AiResponse
    {
        $model = $p->options['model'] ?? $this->cfg['model'];

        $prism = Prism::text()
            ->using($this->provider, $model)
            ->withMessages($p->messages)
            ->usingTemperature($p->options['temperature'] ?? 0.3);

        if ($p->systemPrompt) {
            $prism = $prism->withSystemPrompt($p->systemPrompt);
        }

        if (!empty($p->options['tools'])) {
            $prism = $prism->withTools($p->options['tools']);
        }

        if (!empty($p->options['schema'])) {
            $prism = $prism->asStructuredOutput($p->options['schema']);
        }

        if (!empty($p->options['max_tokens'])) {
            $prism = $prism->withMaxTokens((int) $p->options['max_tokens']);
        }

        $result = $prism->asText();

        $usage = $this->mapUsage($result->usage);
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        $toolCalls = [];
        foreach ($result->toolCalls ?? [] as $tc) {
            $toolCalls[] = [
                'id'        => $tc->id ?? null,
                'name'      => $tc->name ?? null,
                'arguments' => $tc->arguments ?? [],
            ];
        }

        return new AiResponse(
            ok: true,
            content: $result->text,
            usage: $usage,
            raw: [],
            toolCalls: $toolCalls,
        );
    }

    private function sendEmbed(AiPayload $p): AiResponse
    {
        $model = $p->options['embed_model'] ?? $this->cfg['embed_model'] ?? null;

        $input = $p->messages[0] ?? null;
        if ($input === null) {
            return new AiResponse(false, null, [], [], 'embed_input_missing');
        }

        $text = $input instanceof UserMessage
            ? $input->content
            : (string) $input;

        $result = Prism::embeddings()
            ->using($this->provider, $model)
            ->fromInput($text)
            ->asEmbeddings();

        $usage = [
            'driver'    => $this->provider,
            'tokens_in' => $result->usage->tokens ?? null,
        ];
        $usage['cost'] = Cost::calc($this->provider, $usage, $this->cfg);

        $vector = $result->embeddings[0]->embedding ?? [];

        return new AiResponse(
            ok: true,
            content: json_encode($vector),
            usage: $usage,
        );
    }

    private function mapUsage(?object $usage): array
    {
        if ($usage === null) {
            return ['driver' => $this->provider];
        }

        return [
            'driver'             => $this->provider,
            'tokens_in'          => $usage->promptTokens ?? null,
            'tokens_out'         => $usage->completionTokens ?? null,
            'cache_read_tokens'  => $usage->cacheReadInputTokens ?? null,
            'cache_write_tokens' => $usage->cacheWriteInputTokens ?? null,
        ];
    }
}
