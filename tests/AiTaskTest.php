<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Orchestra\Testbench\TestCase;

class AiTaskTest extends TestCase
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

    private function makeTask(bool $shouldRun = true): AiTask
    {
        return new class($shouldRun) extends AiTask {
            public function __construct(private bool $runs) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function shouldRun(): bool { return $this->runs; }
        };
    }

    public function test_should_run_defaults_to_true(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertTrue($task->shouldRun());
    }

    public function test_should_run_can_be_overridden_to_false(): void
    {
        $this->assertFalse($this->makeTask(false)->shouldRun());
    }

    public function test_run_is_skipped_when_should_run_returns_false(): void
    {
        $run = AiRun::create([
            'tenant_id' => 'test',
            'task'      => 'demo',
            'driver'    => 'null',
            'modality'  => 'text',
            'dispatch'  => 'queue',
            'status'    => 'queued',
            'request'   => ['modality' => 'text'],
        ]);

        $run->skip('guard_rejected');
        $run->refresh();

        $this->assertSame('skipped', $run->status);
    }
}
