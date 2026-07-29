<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class RetryTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeTask(int $maxRetries, bool $acceptable): AiTask
    {
        return new class($maxRetries, $acceptable) extends AiTask {
            public function __construct(
                private readonly int $max,
                private readonly bool $acceptable,
            ) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', messages: [new \Laravel\Ai\Messages\UserMessage('hi')]); }
            public function serializeForQueue(): array { return [$this->max, $this->acceptable]; }
            public function postprocess(AiResponse $resp): array { return ['ok' => $this->acceptable]; }
            public function maxRetries(): int { return $this->max; }
            public function isAcceptable(AiResponse|array $result): bool { return $this->acceptable; }
        };
    }

    private function makeOkRun(AiTask $task): AiRun
    {
        $run = AiRun::startAsQueue('driverA', $task->toPayload(), $task->context(), $task);
        $run->update(['status' => 'ok', 'response' => ['content' => 'irrelevant']]);

        return $run;
    }

    // ── Unit: AiTask defaults ────────────────────────────────────────────

    public function test_max_retries_and_is_acceptable_default_to_no_retry(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertSame(0, $task->maxRetries());
        $this->assertTrue($task->isAcceptable(['whatever' => true]));
    }

    // ── Integration: PostprocessAiResult ─────────────────────────────────

    public function test_retries_when_result_not_acceptable_and_attempts_remain(): void
    {
        Event::fake([AiTaskCompleted::class]);
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->makeTask(maxRetries: 1, acceptable: false);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        Event::assertNotDispatched(AiTaskCompleted::class);
        Queue::assertPushed(ProcessAiPayload::class, fn (ProcessAiPayload $job): bool => $job->attempt === 1);

        $this->assertSame(2, AiRun::count());
        $this->assertTrue(AiRun::where('idempotency_key', 'like', '%-retry1')->exists());
    }

    public function test_fires_completed_with_attempts_exhausted_when_retries_used_up(): void
    {
        Event::fake([AiTaskCompleted::class]);
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->makeTask(maxRetries: 1, acceptable: false);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 1))->handle();

        Queue::assertNotPushed(ProcessAiPayload::class);
        Event::assertDispatched(AiTaskCompleted::class, fn (AiTaskCompleted $e): bool => $e->attemptsExhausted === true);

        $this->assertSame(1, AiRun::count());
    }

    public function test_fires_completed_normally_when_result_is_acceptable(): void
    {
        Event::fake([AiTaskCompleted::class]);
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->makeTask(maxRetries: 1, acceptable: true);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        Queue::assertNotPushed(ProcessAiPayload::class);
        Event::assertDispatched(AiTaskCompleted::class, fn (AiTaskCompleted $e): bool => $e->attemptsExhausted === false);

        $this->assertSame(1, AiRun::count());
    }

    public function test_never_retries_when_max_retries_is_zero(): void
    {
        Event::fake([AiTaskCompleted::class]);
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->makeTask(maxRetries: 0, acceptable: false);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        Queue::assertNotPushed(ProcessAiPayload::class);
        Event::assertDispatched(AiTaskCompleted::class, fn (AiTaskCompleted $e): bool => $e->attemptsExhausted === true);
    }
}
