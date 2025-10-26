<?php

namespace Fomvasss\AiTasks\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AiMakeTaskCommand extends Command
{
    protected $signature = 'ai:make-task
        {name : Class name (GetImageTask, Products/GetInfoTask)}
        {--queued : Add ShouldQueueAi + QueueableAi}
        {--modality=text : text|chat|image|vision|embed}
        {--force : rewrite the file if it exists}';

    protected $description = 'Generate an AI task (in a project App\AiTasks namespace by default)';

    public function handle(Filesystem $files): int
    {
        $input = trim($this->argument('name')); // напр.: "Orders/InfoOrderTask" або "InfoOrderTask"
        $modality = $this->option('modality') ?: 'text';
        $queued   = (bool) $this->option('queued');

        // Розділяємо шлях по "/" або "\" незалежно від ОС
        $parts = preg_split('#[\\\\/]+#', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($parts)) {
            $this->error('Invalid class name.');
            return self::FAILURE;
        }

        $className   = array_pop($parts);         // "InfoOrderTask"
        $subPath     = implode(DIRECTORY_SEPARATOR, $parts); // "Orders"
        $subNs       = implode('\\', $parts);     // "Orders" -> namespace хвіст

        $baseNs      = 'App\\Ai\\Tasks';
        $namespace   = $subNs ? $baseNs . '\\' . $subNs : $baseNs;

        $baseDir     = app_path('Ai/Tasks');
        $targetDir   = $subPath ? $baseDir . DIRECTORY_SEPARATOR . $subPath : $baseDir;
        $path        = $targetDir . DIRECTORY_SEPARATOR . $className . '.php';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error("File already exists: {$path}. Use --force for rewrite.");
            return self::FAILURE;
        }

        if (! $files->isDirectory($targetDir)) {
            $files->makeDirectory($targetDir, 0755, true);
        }

        $stub = $this->buildStub($namespace, $className, $modality, $queued);
        $files->put($path, $stub);

        $this->info("Created: {$path}");
        return self::SUCCESS;
    }

    protected function buildStub(string $namespace, string $class, string $modality, bool $queued): string
    {
        $queuedUse   = $queued
            ? "use Fomvasss\\AiTasks\\Contracts\\ShouldQueueAi;\nuse Fomvasss\\AiTasks\\Traits\\QueueableAi;\n"
            : "";
        $queuedImpl  = $queued ? " implements ShouldQueueAi" : "";
        $queuedTrait = $queued ? "    use QueueableAi;\n\n" : "";
        
        $taskName = Str::snake(str_replace('Task','', $class));

        $viaQueues = $queued
            ? <<<PHP
    
    public function toQueueArgs(): array
    {
        /*Add your coustructor arguments*/
        return [ /*this->product, this->locale */];
    }

PHP
            : "";

        return <<<PHP
<?php

namespace {$namespace};

use Fomvasss\AiTasks\Contracts\QueueSerializableAi;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\\AiTasks\\Tasks\\AiTask;
use Fomvasss\\AiTasks\\DTO\\AiPayload;
use Fomvasss\\AiTasks\\DTO\\AiResponse;
use Fomvasss\\AiTasks\\Support\\Prompt;
use Fomvasss\\AiTasks\\Support\\Schema;

class {$class} extends AiTask{$queuedImpl} 
{
    public function name(): string
    {
        return '{$taskName}';
    }

    public function modality(): string
    {
        return '{$modality}'; // text|chat|image|vision|embed
    }

    public function toPayload(): AiPayload
    {
        // TODO add your payload generation logic here
        return new AiPayload(
            modality: \$this->modality(),
            messages: [[ 'role' => 'user', 'content' => 'Tell me something interesting']],
            options:  ['temperature' => 0.3],
        );
    }

    public function postprocess(AiResponse \$resp): array|AiResponse
    {
        // TODO add your post-processing logic here
        // Post-processing of responses (can be stored in a database/storage or other your own mechanism)
        // If you expect JSON — parse it and return an array
        return \$resp;
    }
    {$viaQueues}
}

PHP;
    }
}