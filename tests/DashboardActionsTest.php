<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Events\AiRunFailed;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class DashboardActionsTestTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string
    {
        return 'text';
    }

    public function serializeForQueue(): array
    {
        return [$this->text];
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload('text', [new UserMessage($this->text)]);
    }
}

class DashboardActionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    // The dashboard routes run in the 'web' group, which encrypts the session
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
        config(['ai-tasks.store_request' => true]);
    }

    private int $seq = 0;

    private function queuedRun(?\DateTimeInterface $createdAt = null): AiRun
    {
        // Text varies per run: idempotency_key is derived from the task and is unique in the table
        $task = new DashboardActionsTestTask('translate me #'.++$this->seq);
        $run = AiRun::startAsQueue('openai', $task->toPayload(), $task->context(), $task);

        if ($createdAt) {
            // created_at is what scopeStuck() measures while a run is still queued
            $run->forceFill(['created_at' => $createdAt])->saveQuietly();
            $run->refresh();
        }

        return $run;
    }

    public function test_a_run_queued_longer_than_the_threshold_is_stuck(): void
    {
        $stale = $this->queuedRun(now()->subHour());
        $fresh = $this->queuedRun();

        $this->assertTrue($stale->isStuck());
        $this->assertFalse($fresh->isStuck());
        $this->assertSame([$stale->id], AiRun::query()->stuck()->pluck('id')->all());
    }

    /** A long-but-live run must not be flagged: its worker is still on it. */
    public function test_a_running_run_with_recent_progress_is_not_stuck(): void
    {
        $run = $this->queuedRun(now()->subHour());
        $run->markRunning();

        $this->assertFalse($run->fresh()->isStuck());
        $this->assertFalse($run->fresh()->canRetry());
    }

    public function test_finished_runs_are_never_stuck_but_failed_ones_stay_retryable(): void
    {
        $ok = $this->queuedRun(now()->subHour());
        $ok->update(['status' => 'ok']);

        $dead = $this->queuedRun(now()->subHour());
        $dead->update(['status' => 'dead']);

        $this->assertFalse($ok->fresh()->isStuck());
        $this->assertFalse($ok->fresh()->canRetry());
        $this->assertTrue($dead->fresh()->canRetry());
    }

    public function test_retry_redispatches_a_stuck_run_and_clears_its_error(): void
    {
        Queue::fake();

        $run = $this->queuedRun(now()->subHour());
        $run->update(['error' => 'lost']);

        $this->post(route('ai-tasks.retry', $run->id))->assertRedirect();

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->error);
        Queue::assertPushed(ProcessAiPayload::class, fn (ProcessAiPayload $job) => $job->runId === $run->id);
    }

    public function test_retry_is_refused_for_a_run_that_is_neither_failed_nor_stuck(): void
    {
        Queue::fake();

        $run = $this->queuedRun();

        $this->post(route('ai-tasks.retry', $run->id))->assertRedirect();

        Queue::assertNothingPushed();
    }

    /** Without store_request there are no ctor args to revive — must degrade, not blow up. */
    public function test_retry_reports_a_run_that_cannot_be_reconstructed(): void
    {
        Queue::fake();

        $run = $this->queuedRun(now()->subHour());
        $run->update(['request' => ['modality' => 'text', 'task_class' => DashboardActionsTestTask::class]]);

        $this->post(route('ai-tasks.retry', $run->id))->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertSame('queued', $run->fresh()->status);
    }

    /**
     * abandon() is bookkeeping, not a new failure: consumers listening on AiRunFailed would
     * otherwise be notified about an admin's button click hours after the actual failure.
     */
    public function test_mark_dead_closes_the_run_without_firing_a_failure_event(): void
    {
        Event::fake([AiRunFailed::class]);

        $run = $this->queuedRun(now()->subHour());

        $this->post(route('ai-tasks.dead', $run->id))->assertRedirect();

        $run->refresh();
        $this->assertSame('dead', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error);
        Event::assertNotDispatched(AiRunFailed::class);
    }

    /**
     * Regression: the list used to order by started_at alone, which puts a never-started run
     * first on Postgres and last on MySQL — the same data in a different order per driver.
     */
    public function test_a_never_started_run_is_ordered_by_its_creation_time(): void
    {
        $old = $this->queuedRun(now()->subDay());
        $recent = $this->queuedRun(now()->subHour());

        $started = $this->queuedRun();
        $started->update(['status' => 'running', 'started_at' => now()]);

        $ids = $this->get(route('ai-tasks.data'))->json('runs.*.id');

        $this->assertSame([$started->id, $recent->id, $old->id], $ids);
    }

    public function test_stuck_filter_and_stat_expose_stalled_runs(): void
    {
        $stuck = $this->queuedRun(now()->subHour());
        $this->queuedRun();

        $response = $this->get(route('ai-tasks.data', ['status' => 'stuck']));

        $this->assertSame([$stuck->id], $response->json('runs.*.id'));
        $this->assertSame(1, $response->json('stats.stuck'));
    }
}
