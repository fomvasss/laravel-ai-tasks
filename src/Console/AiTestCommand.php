<?php

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Tasks\ChatAssistTask;
use Fomvasss\AiTasks\Tasks\GenerateProductDescription;
use Illuminate\Console\Command;

class AiTestCommand extends Command
{
    protected $signature = 'ai:test {--fixture=} {--locale=en} {--case=GenerateProductDescription}';
    protected $description = 'Test run of GenerateProductDescription on fixture';

    public function handle(): int
    {
        if ($this->option('case') === 'GenerateProductDescription') {
            $path = $this->option('fixture') ?: base_path('vendor/fomvasss/laravel-ai-tasks/tests/stubs/product_123.json');
            $data = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

            $resp = AI::send(new GenerateProductDescription((object)$data, $this->option('locale')));
        } elseif ($this->option('case') === 'ChatAssistTask') {
            $history = [
                ['role'=>'user','content'=>'Where is my order #A123?'],
            ];
            
            $resp = AI::send(new ChatAssistTask(history: $history, locale: $this->option('locale')));
        } else {
            $resp = (object)['content' => 'Select case: GenerateProductDescription OR ChatAssistTask'];
        }
        
        $this->info('OK: ' . substr($resp->content ?? '', 0, 200).'...');
        
        return self::SUCCESS;
    }
}
