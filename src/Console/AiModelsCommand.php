<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiModelsCommand extends Command
{
    protected $signature = 'ai:models
        {driver? : Driver name (openai, gemini, anthropic). Omit to list all configured drivers.}
        {--filter= : Filter model IDs by substring}
        {--detail  : Show token limits, release date, capabilities}';

    protected $description = 'List available models from AI provider APIs';

    public function handle(): int
    {
        $driver = $this->argument('driver');
        $filter = $this->option('filter');
        $detail = $this->option('detail');

        $drivers = $driver
            ? [$driver]
            : array_keys(config('ai-tasks.drivers', []));

        foreach ($drivers as $name) {
            $cfg    = config("ai-tasks.drivers.{$name}", []);
            $apiKey = config("ai.providers.{$name}.key");

            if (empty($apiKey)) {
                $this->line("<fg=yellow>  {$name}: no api_key configured in config/ai.php, skipping</>");
                continue;
            }

            $cfg['api_key'] = $apiKey;

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$name}</>");

            [$headers, $rows] = match ($name) {
                'openai'    => $this->fetchOpenAI($cfg, $filter, $detail),
                'gemini'    => $this->fetchGemini($cfg, $filter, $detail),
                'anthropic' => $this->fetchAnthropic($cfg, $filter, $detail),
                default     => $this->fetchOpenAICompatible($name, $cfg, $filter, $detail),
            };

            if ($rows === null) {
                $this->line("  <fg=yellow>No model listing available for [{$name}]</>");
                $this->line("  Configured model: <fg=green>{$cfg['model']}</>");
                continue;
            }

            if (empty($rows)) {
                $this->line('  <fg=yellow>No models found (check filter or API key permissions)</>');
                continue;
            }

            $configured = $cfg['model'] ?? null;
            $rows = array_map(function (array $row) use ($configured) {
                $row[0] = $row[0] === $configured ? "<fg=green>{$row[0]} ✓</>" : $row[0];
                return $row;
            }, $rows);

            $this->table($headers, $rows);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function fetchOpenAI(array $cfg, ?string $filter, bool $detail): array
    {
        $baseUrl = rtrim(config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');
        try {
            $resp = Http::timeout(10)->withToken($cfg['api_key'])->get("{$baseUrl}/models");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('OpenAI connection error: ' . $e->getMessage());
            return [[], []];
        }

        if (! $resp->successful()) {
            $this->error('OpenAI API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [[], []];
        }

        $rows = collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn($m) => ! $filter || str_contains($m['id'], $filter));

        if ($detail) {
            return [
                ['Model ID', 'Owner', 'Released'],
                $rows->map(fn($m) => [
                    $m['id'],
                    $m['owned_by'] ?? '',
                    isset($m['created']) ? date('Y-m-d', $m['created']) : '',
                ])->values()->all(),
            ];
        }

        return [
            ['Model ID', 'Owner'],
            $rows->map(fn($m) => [$m['id'], $m['owned_by'] ?? ''])->values()->all(),
        ];
    }

    private function fetchGemini(array $cfg, ?string $filter, bool $detail): array
    {
        try {
            $resp = Http::timeout(10)->withQueryParameters(['key' => $cfg['api_key']])
                ->get('https://generativelanguage.googleapis.com/v1beta/models');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('Gemini connection error: ' . $e->getMessage());
            return [[], []];
        }

        if (! $resp->successful()) {
            $this->error('Gemini API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [[], []];
        }

        $rows = collect($resp->json('models', []))
            ->sortBy('name')
            ->filter(fn($m) => ! $filter || str_contains($m['name'], $filter));

        if ($detail) {
            return [
                ['Model ID', 'Context (in)', 'Max out', 'Methods'],
                $rows->map(fn($m) => [
                    str_replace('models/', '', $m['name']),
                    isset($m['inputTokenLimit'])  ? number_format($m['inputTokenLimit'])  : '',
                    isset($m['outputTokenLimit']) ? number_format($m['outputTokenLimit']) : '',
                    implode(', ', $m['supportedGenerationMethods'] ?? []),
                ])->values()->all(),
            ];
        }

        return [
            ['Model ID', 'Methods'],
            $rows->map(fn($m) => [
                str_replace('models/', '', $m['name']),
                implode(', ', $m['supportedGenerationMethods'] ?? []),
            ])->values()->all(),
        ];
    }

    private function fetchAnthropic(array $cfg, ?string $filter, bool $detail): array
    {
        $version = '2023-06-01';
        try {
            $resp = Http::timeout(10)->withHeaders([
                'x-api-key'         => $cfg['api_key'],
                'anthropic-version' => $version,
            ])->get('https://api.anthropic.com/v1/models');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('Anthropic connection error: ' . $e->getMessage());
            return [[], []];
        }

        if (! $resp->successful()) {
            $this->error('Anthropic API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [[], []];
        }

        $rows = collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn($m) => ! $filter || str_contains($m['id'], $filter));

        if ($detail) {
            return [
                ['Model ID', 'Display name', 'Context (in)', 'Max out', 'Released', 'Capabilities'],
                $rows->map(fn($m) => [
                    $m['id'],
                    $m['display_name'] ?? '',
                    isset($m['max_input_tokens']) ? number_format($m['max_input_tokens']) : '',
                    isset($m['max_tokens'])       ? number_format($m['max_tokens'])       : '',
                    isset($m['created_at'])       ? substr($m['created_at'], 0, 10)       : '',
                    $this->anthropicCaps($m['capabilities'] ?? []),
                ])->values()->all(),
            ];
        }

        return [
            ['Model ID', 'Display name', 'Released'],
            $rows->map(fn($m) => [
                $m['id'],
                $m['display_name'] ?? '',
                isset($m['created_at']) ? substr($m['created_at'], 0, 10) : '',
            ])->values()->all(),
        ];
    }

    private function fetchOpenAICompatible(string $name, array $cfg, ?string $filter, bool $detail): array
    {
        $baseUrl = rtrim(config("ai.providers.{$name}.url", ''), '/');

        if (! $baseUrl) {
            return [null, null];
        }

        try {
            $resp = Http::timeout(5)->withToken($cfg['api_key'])->get("{$baseUrl}/models");
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $this->line("  <fg=yellow>Cannot connect to {$baseUrl} — is the service running?</>");
            return [null, null];
        }

        if (! $resp->successful()) {
            return [null, null];
        }

        $data = $resp->json('data');

        if (! is_array($data)) {
            return [null, null];
        }

        $rows = collect($data)
            ->sortBy('id')
            ->filter(fn($m) => ! $filter || str_contains($m['id'] ?? '', $filter));

        $hasContext = $rows->contains(fn($m) => isset($m['context_window']));

        if ($detail && $hasContext) {
            return [
                ['Model ID', 'Context (in)', 'Max out', 'Released'],
                $rows->map(fn($m) => [
                    $m['id'],
                    isset($m['context_window'])          ? number_format($m['context_window'])          : '',
                    isset($m['max_completion_tokens'])   ? number_format($m['max_completion_tokens'])   : '',
                    isset($m['created'])                 ? date('Y-m-d', $m['created'])                 : '',
                ])->values()->all(),
            ];
        }

        return [
            ['Model ID', 'Owner', 'Released'],
            $rows->map(fn($m) => [
                $m['id'],
                $m['owned_by'] ?? '',
                isset($m['created']) ? date('Y-m-d', $m['created']) : '',
            ])->values()->all(),
        ];
    }

    private function anthropicCaps(array $caps): string
    {
        $flags = ['thinking', 'image_input', 'pdf_input', 'batch', 'citations', 'structured_outputs'];

        return implode(', ', array_filter($flags, fn($f) => ! empty($caps[$f]['supported'])));
    }
}
