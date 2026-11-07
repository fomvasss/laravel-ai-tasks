<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\RunRetrier;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class RetentionTestTask extends AiTask
{
    public function __construct(private readonly string $text = 'default') {}

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
        return new AiPayload('text', [$this->text]);
    }
}

class RetentionTest extends TestCase
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
    }

    private function makeRun(string $status, int $daysAgo, array $request = [], array $response = []): AiRun
    {
        $run = AiRun::create([
            'tenant_id' => 'default',
            'task' => 'retention_test',
            'driver' => 'openai',
            'modality' => 'text',
            'dispatch' => 'queue',
            'status' => $status,
            'tokens_in' => 120,
            'tokens_out' => 30,
            'cost' => 0.0015,
            'duration_ms' => 900,
            'request' => [
                'modality' => 'text',
                'options' => ['temperature' => 0.2, 'instructions' => 'Speak like a pirate'],
                'meta' => ['source' => 'chat'],
                'task_class' => RetentionTestTask::class,
                'execution_context' => ['user_id' => 7],
                'task_args' => ['secret text'],
                'system' => 'You are a helpful assistant',
                'messages' => [['role' => 'user', 'content' => 'call me at +380000000000']],
                'dispatch_id' => 'd-1',
                'available_at' => null,
                ...$request,
            ],
            'response' => [
                'content' => 'Sure, calling +380000000000',
                'structured' => ['phone' => '+380000000000'],
                'tool_calls' => [['name' => 'call', 'arguments' => ['phone' => '+380000000000']]],
                'finish_reason' => 'stop',
                ...$response,
            ],
        ]);

        $run->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $run->fresh();
    }

    public function test_retention_is_off_by_default(): void
    {
        $this->assertNull(config('ai-tasks.retention.payload_days'));
        $this->assertNull(config('ai-tasks.retention.days'));

        $run = $this->makeRun('ok', 400);

        $this->artisan('ai:prune')->expectsOutputToContain('Retention is off')->assertSuccessful();

        $run = AiRun::find($run->id);
        $this->assertSame('You are a helpful assistant', $run->request['system']);
        $this->assertSame('Sure, calling +380000000000', $run->response['content']);
        $this->assertFalse($run->isPayloadPruned());
    }

    public function test_strips_the_payload_of_old_finished_runs_and_keeps_the_metrics(): void
    {
        config(['ai-tasks.retention.payload_days' => 30]);

        $run = $this->makeRun('ok', 31);

        $this->artisan('ai:prune')->expectsOutputToContain('stripped the payload of 1')->assertSuccessful();

        $run = AiRun::find($run->id);

        foreach (['system', 'messages', 'task_args'] as $key) {
            $this->assertArrayNotHasKey($key, $run->request);
        }
        foreach (['content', 'structured', 'tool_calls'] as $key) {
            $this->assertArrayNotHasKey($key, $run->response);
        }
        $this->assertArrayNotHasKey('instructions', $run->request['options']);
        $this->assertTrue($run->isPayloadPruned());

        $this->assertSame('ok', $run->status);
        $this->assertSame(120, $run->tokens_in);
        $this->assertSame(30, $run->tokens_out);
        $this->assertSame(0.0015, $run->cost);
        $this->assertSame(900, $run->duration_ms);
    }

    public function test_keeps_the_keys_the_package_reads_after_the_run(): void
    {
        config(['ai-tasks.retention.payload_days' => 30]);

        $superseded = $this->makeRun('error', 40, response: ['superseded_by' => 'other-run']);
        $resumed = $this->makeRun('ok', 40, ['meta' => ['resumed_from' => 'paused-run']], [
            'provider_run_id' => 'prov-1',
            'pending_approvals' => [['tool' => 'call', 'arguments' => ['phone' => '+380000000000']]],
            'resume' => ['messages' => [['role' => 'user', 'content' => 'hi']], 'expires_at' => '2026-01-01T00:00:00+00:00', 'resumed_at' => '2026-01-01T00:00:00+00:00'],
        ]);

        $this->artisan('ai:prune')->assertSuccessful();

        $superseded = AiRun::find($superseded->id);
        $this->assertTrue($superseded->isSuperseded());
        $this->assertSame(1, AiRun::whereNotNull('response->superseded_by')->count());

        $resumed = AiRun::find($resumed->id);
        $this->assertTrue($resumed->isResume());
        $this->assertSame('prov-1', $resumed->response['provider_run_id']);
        $this->assertSame(1, AiRun::where('response->provider_run_id', 'prov-1')->count());
        $this->assertSame('stop', $resumed->response['finish_reason']);
        $this->assertArrayNotHasKey('pending_approvals', $resumed->response);
        $this->assertSame(['expires_at' => '2026-01-01T00:00:00+00:00', 'resumed_at' => '2026-01-01T00:00:00+00:00'], $resumed->response['resume']);

        foreach (['modality' => 'text', 'task_class' => RetentionTestTask::class, 'dispatch_id' => 'd-1', 'execution_context' => ['user_id' => 7]] as $key => $value) {
            $this->assertSame($value, $superseded->request[$key]);
        }
        $this->assertArrayHasKey('available_at', $superseded->request);
        $this->assertSame(['temperature' => 0.2], $superseded->request['options']);
        $this->assertSame(['source' => 'chat'], $superseded->request['meta']);
        $this->assertSame(['user_id' => 7], $superseded->executionContext());
    }

    public function test_runs_in_flight_and_recent_runs_are_not_touched(): void
    {
        config(['ai-tasks.retention.payload_days' => 30, 'ai-tasks.retention.days' => 60]);

        $inFlight = array_map(fn (string $status): AiRun => $this->makeRun($status, 400), ['queued', 'running', 'waiting', 'paused']);
        $recent = $this->makeRun('ok', 29);

        $this->artisan('ai:prune')->expectsOutputToContain('Deleted 0 run(s), stripped the payload of 0')->assertSuccessful();

        foreach ([...$inFlight, $recent] as $run) {
            $run = AiRun::find($run->id);
            $this->assertNotNull($run);
            $this->assertSame('You are a helpful assistant', $run->request['system']);
            $this->assertSame('Sure, calling +380000000000', $run->response['content']);
        }
    }

    public function test_deletes_old_finished_rows_when_days_is_set(): void
    {
        config(['ai-tasks.retention.days' => 90]);

        $old = $this->makeRun('ok', 91);
        $oldDead = $this->makeRun('dead', 200);
        $oldQueued = $this->makeRun('queued', 200);
        $recent = $this->makeRun('ok', 89);

        $this->artisan('ai:prune')->expectsOutputToContain('Deleted 2 run(s), stripped the payload of 0')->assertSuccessful();

        $this->assertNull(AiRun::find($old->id));
        $this->assertNull(AiRun::find($oldDead->id));
        $this->assertNotNull(AiRun::find($oldQueued->id));
        // payload_days is off — the surviving row keeps its payload
        $this->assertSame('You are a helpful assistant', AiRun::find($recent->id)->request['system']);
    }

    public function test_works_in_chunks_and_is_idempotent(): void
    {
        config(['ai-tasks.retention.payload_days' => 30]);

        foreach (range(1, 5) as $i) {
            $this->makeRun('ok', 31 + $i);
        }

        $this->artisan('ai:prune', ['--dry-run' => true])->expectsOutputToContain('Payloads to strip: 5')->assertSuccessful();
        $this->assertSame(0, AiRun::whereNotNull('request->payload_pruned_at')->count());

        $this->artisan('ai:prune', ['--chunk' => 2])->expectsOutputToContain('stripped the payload of 5')->assertSuccessful();
        $this->artisan('ai:prune')->expectsOutputToContain('stripped the payload of 0')->assertSuccessful();

        $this->assertSame(5, AiRun::whereNotNull('request->payload_pruned_at')->count());
    }

    /**
     * Without task_args a task whose parameters all have defaults would still rebuild — and run
     * as a different task. A pruned run must be refused on every retry path instead.
     */
    public function test_a_pruned_run_is_not_retried(): void
    {
        Queue::fake();
        config(['ai-tasks.retention.payload_days' => 30]);

        $run = $this->makeRun('error', 31);
        $this->assertTrue($run->canRetry());

        $this->artisan('ai:prune')->assertSuccessful();
        $run = AiRun::find($run->id);

        $this->assertFalse($run->canRetry());
        $this->assertNull(RunRetrier::reconstruct($run));

        $run->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        $this->artisan('ai:retry')->expectsOutputToContain('payload pruned')->assertSuccessful();

        $this->post(route('ai-tasks.retry', $run->id))
            ->assertRedirect()
            ->assertSessionHas('ai-tasks-flash', fn (array $flash): bool => str_contains($flash[1], 'Payload pruned'));

        $this->get(route('ai-tasks.show', $run->id))->assertOk()->assertSee('payload pruned');

        Queue::assertNothingPushed();
        $this->assertSame('error', $run->fresh()->status);
    }
}
