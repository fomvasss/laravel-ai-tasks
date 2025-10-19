<?php

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Tasks\GenerateProductDescription;
use Illuminate\Console\Command;

class AiTestCommand extends Command
{
    protected $signature = 'ai:test {--fixture=} {--locale=uk}';
    protected $description = 'Test run of GenerateProductDescription on fixture';

    public function handle(): int
    {
        $path = $this->option('fixture') ?: base_path('vendor/fomvasss/laravel-ai-tasks/tests/stubs/product_123.json');
        $data = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

        $resp = AI::send(new GenerateProductDescription((object)$data, $this->option('locale')));
        $this->info('OK: ' . substr($resp->content ?? '', 0, 200).'...');
        return self::SUCCESS;
    }
}
