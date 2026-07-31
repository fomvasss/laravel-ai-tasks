<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Exceptions\ModelListingException;
use Fomvasss\AiTasks\Exceptions\ModelListingUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches available models from a provider's own API (not config/ai-tasks.php).
 * Used by `ai:models`; reusable wherever a raw, unformatted model list is needed.
 */
final class ModelLister
{
    /**
     * Default listing base URLs for drivers that don't set 'url' in config/ai.php —
     * mirrors the hardcoded fallbacks each laravel/ai Gateway already uses internally.
     */
    private const DEFAULT_LISTING_URLS = [
        'groq'       => 'https://api.groq.com/openai/v1',
        'mistral'    => 'https://api.mistral.ai/v1',
        'deepseek'   => 'https://api.deepseek.com/v1',
        'xai'        => 'https://api.x.ai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    /**
     * @param  array{api_key: string}  $cfg
     * @return array<int, array{id: string, display_name: ?string, owner: ?string, created: ?string, context_in: ?int, context_out: ?int, methods: ?string, capabilities: ?string}>
     *
     * @throws ModelListingUnavailableException when the driver has no listing endpoint available
     * @throws ModelListingException on connection or API errors
     */
    public function forDriver(string $name, array $cfg, ?string $filter = null): array
    {
        return match ($name) {
            'openai'    => $this->openAi($cfg, $filter),
            'gemini'    => $this->gemini($cfg, $filter),
            'anthropic' => $this->anthropic($cfg, $filter),
            default     => $this->openAiCompatible($name, $cfg, $filter),
        };
    }

    private function openAi(array $cfg, ?string $filter): array
    {
        $baseUrl = rtrim(config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');

        try {
            $resp = Http::timeout(10)->withToken($cfg['api_key'])->get("{$baseUrl}/models");
        } catch (ConnectionException $e) {
            throw new ModelListingException('OpenAI connection error: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            throw new ModelListingException('OpenAI API error: ' . ($resp->json('error.message') ?? $resp->body()));
        }

        return collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn (array $m) => ! $filter || str_contains($m['id'], $filter))
            ->map(fn (array $m) => [
                'id'           => $m['id'],
                'display_name' => null,
                'owner'        => $m['owned_by'] ?? null,
                'created'      => isset($m['created']) ? date('Y-m-d', $m['created']) : null,
                'context_in'   => null,
                'context_out'  => null,
                'methods'      => null,
                'capabilities' => null,
            ])
            ->values()
            ->all();
    }

    private function gemini(array $cfg, ?string $filter): array
    {
        try {
            $resp = Http::timeout(10)->withQueryParameters(['key' => $cfg['api_key']])
                ->get('https://generativelanguage.googleapis.com/v1beta/models');
        } catch (ConnectionException $e) {
            throw new ModelListingException('Gemini connection error: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            throw new ModelListingException('Gemini API error: ' . ($resp->json('error.message') ?? $resp->body()));
        }

        return collect($resp->json('models', []))
            ->sortBy('name')
            ->filter(fn (array $m) => ! $filter || str_contains($m['name'], $filter))
            ->map(fn (array $m) => [
                'id'           => str_replace('models/', '', $m['name']),
                'display_name' => null,
                'owner'        => null,
                'created'      => null,
                'context_in'   => $m['inputTokenLimit'] ?? null,
                'context_out'  => $m['outputTokenLimit'] ?? null,
                'methods'      => implode(', ', $m['supportedGenerationMethods'] ?? []),
                'capabilities' => null,
            ])
            ->values()
            ->all();
    }

    private function anthropic(array $cfg, ?string $filter): array
    {
        try {
            $resp = Http::timeout(10)->withHeaders([
                'x-api-key'         => $cfg['api_key'],
                'anthropic-version' => '2023-06-01',
            ])->get('https://api.anthropic.com/v1/models');
        } catch (ConnectionException $e) {
            throw new ModelListingException('Anthropic connection error: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            throw new ModelListingException('Anthropic API error: ' . ($resp->json('error.message') ?? $resp->body()));
        }

        return collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn (array $m) => ! $filter || str_contains($m['id'], $filter))
            ->map(fn (array $m) => [
                'id'           => $m['id'],
                'display_name' => $m['display_name'] ?? null,
                'owner'        => null,
                'created'      => isset($m['created_at']) ? substr($m['created_at'], 0, 10) : null,
                'context_in'   => $m['max_input_tokens'] ?? null,
                'context_out'  => $m['max_tokens'] ?? null,
                'methods'      => null,
                'capabilities' => $this->anthropicCaps($m['capabilities'] ?? []),
            ])
            ->values()
            ->all();
    }

    private function openAiCompatible(string $name, array $cfg, ?string $filter): array
    {
        $baseUrl = rtrim(config("ai.providers.{$name}.url") ?? self::DEFAULT_LISTING_URLS[$name] ?? '', '/');

        if (! $baseUrl) {
            throw new ModelListingUnavailableException();
        }

        try {
            $resp = Http::timeout(5)->withToken($cfg['api_key'])->get("{$baseUrl}/models");
        } catch (ConnectionException) {
            throw new ModelListingUnavailableException("Cannot connect to {$baseUrl} — is the service running?");
        }

        if (! $resp->successful()) {
            throw new ModelListingUnavailableException();
        }

        $data = $resp->json('data');

        if (! is_array($data)) {
            throw new ModelListingUnavailableException();
        }

        return collect($data)
            ->sortBy('id')
            ->filter(fn (array $m) => ! $filter || str_contains($m['id'] ?? '', $filter))
            ->map(fn (array $m) => [
                'id'           => $m['id'],
                'display_name' => null,
                'owner'        => $m['owned_by'] ?? null,
                'created'      => isset($m['created']) ? date('Y-m-d', $m['created']) : null,
                'context_in'   => $m['context_window'] ?? null,
                'context_out'  => $m['max_completion_tokens'] ?? null,
                'methods'      => null,
                'capabilities' => null,
            ])
            ->values()
            ->all();
    }

    private function anthropicCaps(array $caps): string
    {
        $flags = ['thinking', 'image_input', 'pdf_input', 'batch', 'citations', 'structured_outputs'];

        return implode(', ', array_filter($flags, fn (string $f) => ! empty($caps[$f]['supported'])));
    }
}
