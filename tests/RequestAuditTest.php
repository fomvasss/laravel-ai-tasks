<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class RequestAuditTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
    }

    private function makeTask(array $options): AiTask
    {
        return new class($options) extends AiTask {
            public function __construct(private array $options) {}
            public function modality(): string { return 'text'; }
            public function serializeForQueue(): array { return ['x']; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', [new UserMessage('hi')], options: $this->options);
            }
        };
    }

    public function test_attachments_are_omitted_from_stored_request_options(): void
    {
        $task = $this->makeTask([
            'temperature' => 0.3,
            'attachments' => [str_repeat('A', 100_000), str_repeat('B', 100_000)],
        ]);

        $run = AiRun::start('null', $task->toPayload(), $task->context(), $task);

        $this->assertSame('[2 attachment(s) omitted]', $run->request['options']['attachments']);
        $this->assertSame(0.3, $run->request['options']['temperature']);
    }

    public function test_options_without_attachments_are_stored_as_is(): void
    {
        $task = $this->makeTask(['temperature' => 0.1, 'max_tokens' => 256]);

        $run = AiRun::start('null', $task->toPayload(), $task->context(), $task);

        $this->assertSame(['temperature' => 0.1, 'max_tokens' => 256], $run->request['options']);
    }

    public function test_flush_custom_provider_aliases_removes_runtime_aliases_only(): void
    {
        config([
            'ai.providers.openai' => ['key' => 'real'],
            'ai.providers.custom_abc123' => ['driver' => 'openai', 'key' => 'override'],
        ]);

        $provider = new AiServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'flushCustomProviderAliases');
        $method->invoke($provider);

        $this->assertNull(config('ai.providers.custom_abc123'));
        $this->assertSame(['key' => 'real'], config('ai.providers.openai'));
    }
}
