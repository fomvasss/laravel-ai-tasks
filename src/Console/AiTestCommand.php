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
            
            $data = [
                'id' => 'a03479ef-599e-46de-b0e2-3b3c134abe6b',
                'title' => 'Wireless Bluetooth Headphones',
                'features' => [
                    'High-quality sound with deep bass',
                    'Comfortable over-ear design',
                    'Built-in microphone for hands-free calls',
                    'Long-lasting battery life (up to 20 hours)',
                ],
            ];

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
