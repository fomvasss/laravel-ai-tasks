<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AiMakeTaskCommand extends Command
{
    protected $signature = 'ai:make-task
        {name : Class name, e.g. SummarizeTask or Orders/SummarizeTask}
        {--queued : Implement ShouldQueueAi}
        {--force : Overwrite if exists}';

    protected $description = 'Generate an AiTask class in App\\Ai\\Tasks';

    public function handle(Filesystem $files): int
    {
        $input  = trim($this->argument('name'));
        $queued = (bool) $this->option('queued');

        $parts     = preg_split('#[\\\\/]+#', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $className = array_pop($parts);
        $subNs     = implode('\\', $parts);
        $subPath   = implode(DIRECTORY_SEPARATOR, $parts);

        $namespace = $subNs ? "App\\Ai\\Tasks\\{$subNs}" : 'App\\Ai\\Tasks';
        $dir       = $subPath ? app_path("Ai/Tasks/{$subPath}") : app_path('Ai/Tasks');
        $path      = "{$dir}/{$className}.php";

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error("Already exists: {$path}. Use --force to overwrite.");
            return self::FAILURE;
        }

        $files->isDirectory($dir) || $files->makeDirectory($dir, 0755, true);
        $files->put($path, $this->buildStub($namespace, $className, $queued));

        $this->info("Created: {$path}");
        return self::SUCCESS;
    }

    private function buildStub(string $namespace, string $class, bool $queued): string
    {
        $taskName       = Str::snake(str_replace('Task', '', $class));
        $implements     = $queued ? ' implements ShouldQueueAi' : '';
        $shouldQueueUse = $queued ? "\nuse Fomvasss\\AiTasks\\Contracts\\ShouldQueueAi;" : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;{$shouldQueueUse}

class {$class} extends AiTask{$implements}
{
    use SerializesModelsAi;

    public function __construct(
        // add your constructor arguments — must be promoted properties, e.g. `private readonly int \$id`
    ) {}

    public function name(): string
    {
        return '{$taskName}';
    }

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: \$this->modality(),
            messages: [new UserMessage('Your prompt here')],
            systemPrompt: null,
        );
    }

    public function postprocess(AiResponse \$response): AiResponse|array
    {
        return \$response;
    }

    // optional hooks: schema(), onCompleted(), maxRetries()/isAcceptable(), tools() — see README
}
PHP;
    }
}
