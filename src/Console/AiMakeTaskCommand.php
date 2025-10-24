<?php

namespace Fomvasss\AiTasks\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AiMakeTaskCommand extends Command
{
    protected $signature = 'ai:make-task
        {name : Class name (without sufix Task, added automaticly)}
        {--queued : Add ShouldQueueAi + QueueableAi}
        {--modality=text : text|chat|image|vision|embed}
        {--namespace=App\\Ai\\Tasks : Namespace tasks file}
        {--force : rewrite the file if it exists}';

    protected $description = 'Generate an AI task (class in a project that emulates Fomvasss\\AiTasks\\Tasks\\AiTask)';

    public function handle(Filesystem $files): int
    {
        $rawName   = trim($this->argument('name'));
        $className = Str::studly($rawName);
        if (! Str::endsWith($className, 'Task')) {
            $className .= 'Task';
        }

        $namespace = rtrim($this->option('namespace'), '\\');
        $modality  = $this->option('modality') ?: 'text';
        $queued    = (bool) $this->option('queued');

        $targetDir = app_path(str_replace("App/", '', str_replace('\\', '/', $namespace)));
        $path      = $targetDir . '/' . $className . '.php';

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
    public function viaQueues(): array
    {
        return [
            'request' => config('ai.queues.default'), 
            'postprocess' => config('ai.queues.post')
        ];
    }
    
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
{$queuedTrait}
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
        // Get the template (you can replace it with your own mechanism)
        \$tpl = Prompt::get('product_description_v3')->render([
            'title'    => 'Sample title',
            'features' => ['A','B'],
            'locale'   => app()->getLocale(),
        ]);

        return new AiPayload(
            modality: \$this->modality(),
            messages: [[ 'role' => 'user', 'content' => \$tpl ]], // unified internal this package format!
            options:  ['temperature' => 0.3],
            template: 'product_description_v3',
            schema:   'product_description_v1'
        );
    }

    public function postprocess(AiResponse \$resp): array|AiResponse
    {
        // Post-processing of responses (can be stored in a database/storage or other your own mechanism)
        // If you expect JSON — parse it and return an array
        try {
            \$data = Schema::parse(\$resp->content ?? '', 'product_description_v1');
            
            return \$data;
            
        } catch (\\Throwable \$e) {
            // If it's not JSON, return the raw response
            return \$resp;
        }
    }
    {$viaQueues}
}

PHP;
    }
}