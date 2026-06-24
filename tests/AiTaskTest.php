<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Facades\AI;
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

    public function test_queue_duplicate_returns_same_run_id_without_exception(): void
    {
        config(['ai-tasks.drivers.null' => []]);

        $task = new class('hello') extends AiTask {
            public function __construct(private string $text) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function serializeForQueue(): array { return [$this->text]; }
        };

        $id1 = AI::queue($task, 'null');
        $id2 = AI::queue($task, 'null');

        $this->assertSame($id1, $id2);
        $this->assertSame(1, AiRun::where('idempotency_key', $task->idempotencyKey())->count());
    }

    public function test_send_always_creates_new_run(): void
    {
        config(['ai-tasks.drivers.null' => []]);

        $task = new class('hello') extends AiTask {
            public function __construct(private string $text) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function serializeForQueue(): array { return [$this->text]; }
        };

        AI::send($task, 'null');
        AI::send($task, 'null');

        $this->assertSame(2, AiRun::where('dispatch', 'sync')->where('idempotency_key', null)->count());
    }

    public function test_no_idempotency_key_when_serialize_returns_empty(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertNull($task->idempotencyKey());
    }

    public function test_queue_throws_when_constructor_params_but_no_serialize(): void
    {
        config(['ai-tasks.drivers.null' => []]);

        $task = new class('hello') extends AiTask {
            public function __construct(private string $text) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            // serializeForQueue() not implemented — returns []
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/serializeForQueue/');

        AI::queue($task, 'null');
    }
}
