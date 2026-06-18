<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiModelsCommand extends Command
{
    protected $signature = 'ai:models
        {driver? : Driver name (openai, gemini, anthropic). Omit to list all configured drivers.}
        {--filter= : Filter model IDs by substring}';

    protected $description = 'List available models from AI provider APIs';

    public function handle(): int
    {
        $driver = $this->argument('driver');
        $filter = $this->option('filter');

        $drivers = $driver
            ? [$driver]
            : array_keys(config('ai.drivers', []));

        foreach ($drivers as $name) {
            $cfg = config("ai.drivers.{$name}");

            if (empty($cfg['api_key'])) {
                $this->line("<fg=yellow>  {$name}: no api_key configured, skipping</>");
                continue;
            }

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$name}</>");

            $rows = match ($name) {
                'openai'    => $this->fetchOpenAI($cfg, $filter),
                'gemini'    => $this->fetchGemini($cfg, $filter),
                'anthropic' => $this->fetchAnthropic($cfg, $filter),
                default     => null,
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

            $this->table(['Model ID', 'Type / Methods'], $rows);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function fetchOpenAI(array $cfg, ?string $filter): array
    {
        $baseUrl = rtrim(config('prism.providers.openai.url', 'https://api.openai.com/v1'), '/');
        $resp    = Http::withToken($cfg['api_key'])->get("{$baseUrl}/models");

        if (! $resp->successful()) {
            $this->error('OpenAI API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [];
        }

        return collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn($m) => ! $filter || str_contains($m['id'], $filter))
            ->map(fn($m) => [$m['id'], $m['object'] ?? ''])
            ->values()
            ->all();
    }

    private function fetchGemini(array $cfg, ?string $filter): array
    {
        $resp = Http::withQueryParameters(['key' => $cfg['api_key']])
            ->get('https://generativelanguage.googleapis.com/v1beta/models');

        if (! $resp->successful()) {
            $this->error('Gemini API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [];
        }

        return collect($resp->json('models', []))
            ->sortBy('name')
            ->filter(fn($m) => ! $filter || str_contains($m['name'], $filter))
            ->map(fn($m) => [
                str_replace('models/', '', $m['name']),
                implode(', ', $m['supportedGenerationMethods'] ?? []),
            ])
            ->values()
            ->all();
    }

    private function fetchAnthropic(array $cfg, ?string $filter): array
    {
        $version = config('prism.providers.anthropic.version', '2023-06-01');
        $resp    = Http::withHeaders([
            'x-api-key'         => $cfg['api_key'],
            'anthropic-version' => $version,
        ])->get('https://api.anthropic.com/v1/models');

        if (! $resp->successful()) {
            $this->error('Anthropic API error: ' . ($resp->json('error.message') ?? $resp->body()));
            return [];
        }

        return collect($resp->json('data', []))
            ->sortBy('id')
            ->filter(fn($m) => ! $filter || str_contains($m['id'], $filter))
            ->map(fn($m) => [$m['id'], $m['type'] ?? ''])
            ->values()
            ->all();
    }
}
