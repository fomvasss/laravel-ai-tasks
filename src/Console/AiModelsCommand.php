<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Exceptions\ModelListingException;
use Fomvasss\AiTasks\Exceptions\ModelListingUnavailableException;
use Illuminate\Console\Command;

class AiModelsCommand extends Command
{
    protected $signature = 'ai:models
        {driver? : Driver name (openai, gemini, anthropic). Omit to list all configured drivers.}
        {--filter= : Filter model IDs by substring}
        {--detail  : Show token limits, release date, capabilities}';

    protected $description = 'List available models from AI provider APIs';

    public function handle(AI $ai): int
    {
        $driver = $this->argument('driver');
        $filter = $this->option('filter');
        $detail = $this->option('detail');

        $drivers = $driver
            ? [$driver]
            : array_keys(config('ai-tasks.drivers', []));

        foreach ($drivers as $name) {
            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$name}</>");

            try {
                $models = $ai->models($name, $filter);
            } catch (AiDriverException) {
                $this->line("  <fg=yellow>no api_key configured in config/ai.php, skipping</>");
                continue;
            } catch (ModelListingUnavailableException $e) {
                if ($e->getMessage() !== '') {
                    $this->line("  <fg=yellow>{$e->getMessage()}</>");
                }
                $this->line("  <fg=yellow>No model listing available for [{$name}]</>");
                $this->line("  Configured model: <fg=green>" . config("ai-tasks.drivers.{$name}.model") . "</>");
                continue;
            } catch (ModelListingException $e) {
                $this->error($e->getMessage());
                $this->line('  <fg=yellow>No models found (check filter or API key permissions)</>');
                continue;
            }

            if (empty($models)) {
                $this->line('  <fg=yellow>No models found (check filter or API key permissions)</>');
                continue;
            }

            $configured = config("ai-tasks.drivers.{$name}.model");
            [$headers, $rows] = $this->toTable($name, $models, $detail, $configured);

            $this->table($headers, $rows);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function toTable(string $name, array $models, bool $detail, ?string $configured): array
    {
        [$headers, $columns] = match ($name) {
            'openai'    => $detail
                ? [['Model ID', 'Owner', 'Released'], ['id', 'owner', 'created']]
                : [['Model ID', 'Owner'], ['id', 'owner']],
            'gemini'    => $detail
                ? [['Model ID', 'Context (in)', 'Max out', 'Methods'], ['id', 'context_in', 'context_out', 'methods']]
                : [['Model ID', 'Methods'], ['id', 'methods']],
            'anthropic' => $detail
                ? [['Model ID', 'Display name', 'Context (in)', 'Max out', 'Released', 'Capabilities'], ['id', 'display_name', 'context_in', 'context_out', 'created', 'capabilities']]
                : [['Model ID', 'Display name', 'Released'], ['id', 'display_name', 'created']],
            default     => ($detail && collect($models)->contains(fn (array $m) => $m['context_in'] !== null))
                ? [['Model ID', 'Context (in)', 'Max out', 'Released'], ['id', 'context_in', 'context_out', 'created']]
                : [['Model ID', 'Owner', 'Released'], ['id', 'owner', 'created']],
        };

        $rows = collect($models)->map(function (array $m) use ($columns, $configured) {
            $row = array_map(function (string $col) use ($m) {
                $value = $m[$col];

                return in_array($col, ['context_in', 'context_out'], true) && $value !== null
                    ? number_format($value)
                    : (string) ($value ?? '');
            }, $columns);

            $row[0] = $m['id'] === $configured ? "<fg=green>{$row[0]} ✓</>" : $row[0];

            return $row;
        })->values()->all();

        return [$headers, $rows];
    }
}
