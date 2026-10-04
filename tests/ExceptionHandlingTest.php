<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiRunFailed;
use Fomvasss\AiTasks\Events\AiTaskFailedFinally;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Support\Failover;
use Fomvasss\AiTasks\Tasks\AiTask;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Orchestra\Testbench\TestCase;

class ExceptionHandlingTest extends TestCase
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

    private function makeTask(): AiTask
    {
        return new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };
    }

    private function throwingDriver(\Throwable $e): AiDriver
    {
        return new class($e) implements AiDriver {
            public function __construct(private \Throwable $e) {}
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse { throw $this->e; }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse { throw $this->e; }
        };
    }

    private function okDriver(float $cost = 0.0): AiDriver
    {
        return new class($cost) implements AiDriver {
            public function __construct(private float $cost) {}
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse
            {
                return new AiResponse(true, 'ok', ['cost' => $this->cost]);
            }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                $cb('ok');
                return new AiResponse(true, 'ok', ['cost' => $this->cost]);
            }
        };
    }

    /** @param array<string, AiDriver> $drivers keyed by driver name */
    private function swapDriverMap(array $drivers): void
    {
        $manager = new class(app(), $drivers) extends AiManager {
            public function __construct($app, private array $driverMap) { parent::__construct($app); }
            protected function createDriver($name): AiDriver { return $this->driverMap[$name]; }
        };

        app()->instance(AiManager::class, $manager);
        app()->instance(AI::class, new AI($manager, app(Router::class)));

        foreach (array_keys($drivers) as $name) {
            config(["ai.providers.{$name}.key" => 'fake']);
        }
    }

    // ── send() ────────────────────────────────────────────────────────────

    public function test_send_marks_run_as_failed_when_driver_throws(): void
    {
        $this->swapDriverMap(['driverA' => $this->throwingDriver(new \RuntimeException('boom'))]);

        try {
            AIFacade::send($this->makeTask(), 'driverA');
            $this->fail('Expected AiDriverException was not thrown.');
        } catch (AiDriverException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $run = AiRun::query()->latest('created_at')->first();

        $this->assertSame('error', $run->status);
        $this->assertSame('boom', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_send_falls_back_to_next_driver_when_first_throws(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(new \RuntimeException('boom')),
            'driverB' => $this->okDriver(),
        ]);

        $resp = AIFacade::send($this->makeTask(), ['driverA', 'driverB']);

        $this->assertTrue($resp->ok);

        $runs = AiRun::query()->orderBy('created_at')->get();

        $this->assertCount(2, $runs);
        $this->assertSame('driverA', $runs[0]->driver);
        $this->assertSame('error', $runs[0]->status);
        $this->assertNotNull($runs[0]->finished_at);
        $this->assertSame('driverB', $runs[1]->driver);
        $this->assertSame('ok', $runs[1]->status);
    }

    public function test_send_rethrows_on_budget_exceeded_and_fails_run_but_keeps_cost(): void
    {
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);

        $this->swapDriverMap(['driverA' => $this->okDriver(cost: 10.0)]);

        $this->expectException(\Fomvasss\AiTasks\Exceptions\BudgetExceededException::class);

        try {
            AIFacade::send($this->makeTask(), 'driverA');
        } finally {
            $run = AiRun::query()->latest('created_at')->first();
            // Виклик провайдера реально відбувся й був оплачений — статус чесний ('error'),
            // але cost збережено, щоб витрата не випала з підрахунків бюджету (issue #7):
            // Budget::getSpentBetween() сумує по cost IS NOT NULL, не по status.
            $this->assertSame('error', $run->status);
            $this->assertStringContainsString('Budget exceeded', $run->error);
            $this->assertSame(10.0, $run->cost);
            $this->assertNotNull($run->finished_at);
        }
    }

    public function test_budget_counts_cost_of_failed_runs(): void
    {
        AiRun::create([
            'tenant_id' => 'default',
            'task' => 'prior',
            'driver' => 'driverA',
            'modality' => 'text',
            'dispatch' => 'sync',
            'status' => 'error',
            'error' => 'Budget exceeded for tenant [default]',
            'cost' => 10.0,
            'request' => ['modality' => 'text'],
            'finished_at' => now(),
        ]);

        $spent = app(\Fomvasss\AiTasks\Support\Budget::class)->getMonthlySpent('default');

        $this->assertSame(10.0, $spent);
    }

    public function test_send_preflight_blocks_before_any_driver_call_when_already_over_budget(): void
    {
        AiRun::create([
            'tenant_id'   => 'default',
            'task'        => 'prior',
            'driver'      => 'driverA',
            'modality'    => 'text',
            'dispatch'    => 'sync',
            'status'      => 'ok',
            'cost'        => 5.0,
            'request'     => ['modality' => 'text'],
            'finished_at' => now(),
        ]);

        config(['ai-tasks.budgets.default.monthly_usd' => 1.0]);

        // Драйвер, що падає, якщо його реально викличуть — доводить, що pre-flight
        // блокує ще до звернення до провайдера (issue #7, п.1).
        $this->swapDriverMap(['driverA' => $this->throwingDriver(new \RuntimeException('should not be called'))]);

        $this->expectException(\Fomvasss\AiTasks\Exceptions\BudgetExceededException::class);

        AIFacade::send($this->makeTask(), 'driverA');
    }

    // ── stream() ──────────────────────────────────────────────────────────

    public function test_stream_marks_run_as_failed_when_driver_throws(): void
    {
        $this->swapDriverMap(['driverA' => $this->throwingDriver(new \RuntimeException('stream boom'))]);

        try {
            AIFacade::stream($this->makeTask(), fn ($c) => null, 'driverA');
            $this->fail('Expected AiDriverException was not thrown.');
        } catch (AiDriverException $e) {
            $this->assertStringContainsString('stream boom', $e->getMessage());
        }

        $run = AiRun::query()->latest('created_at')->first();

        $this->assertSame('error', $run->status);
        $this->assertSame('stream boom', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_stream_enforces_budget_after_call_and_fails_run_but_keeps_cost(): void
    {
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);

        $this->swapDriverMap(['driverA' => $this->okDriver(cost: 10.0)]);

        $this->expectException(\Fomvasss\AiTasks\Exceptions\BudgetExceededException::class);

        try {
            AIFacade::stream($this->makeTask(), fn ($c) => null, 'driverA');
        } finally {
            $run = AiRun::query()->latest('created_at')->first();
            $this->assertSame('error', $run->status);
            $this->assertSame(10.0, $run->cost);
        }
    }

    // ── queue() / ProcessAiPayload ──────────────────────────────────────────

    public function test_queued_job_fails_run_when_preflight_budget_already_exceeded(): void
    {
        AiRun::create([
            'tenant_id'   => 'default',
            'task'        => 'prior',
            'driver'      => 'driverA',
            'modality'    => 'text',
            'dispatch'    => 'sync',
            'status'      => 'ok',
            'cost'        => 5.0,
            'request'     => ['modality' => 'text'],
            'finished_at' => now(),
        ]);

        config(['ai-tasks.budgets.default.monthly_usd' => 1.0]);

        $this->swapDriverMap(['driverA' => $this->throwingDriver(new \RuntimeException('should not be called'))]);

        $task    = $this->makeTask();
        $payload = $task->toPayload();
        $ctx     = $task->context();
        $run     = AiRun::startAsQueue('driverA', $payload, $ctx, $task);

        $job = new \Fomvasss\AiTasks\Jobs\ProcessAiPayload(
            driverName: 'driverA',
            payload: $payload,
            context: $ctx,
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
        );

        $job->handle(app(AiManager::class));

        $run->refresh();
        // Run вже мав статус 'running' до pre-flight — не повинен зависати там (issue #7).
        $this->assertSame('error', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_queued_job_fails_run_but_keeps_cost_when_postcall_budget_exceeded(): void
    {
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);

        $this->swapDriverMap(['driverA' => $this->okDriver(cost: 10.0)]);

        $task    = $this->makeTask();
        $payload = $task->toPayload();
        $ctx     = $task->context();
        $run     = AiRun::startAsQueue('driverA', $payload, $ctx, $task);

        $job = new \Fomvasss\AiTasks\Jobs\ProcessAiPayload(
            driverName: 'driverA',
            payload: $payload,
            context: $ctx,
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
        );

        $job->handle(app(AiManager::class));

        $run->refresh();
        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('Budget exceeded', $run->error);
        $this->assertSame(10.0, $run->cost);
    }

    // ── failover ──────────────────────────────────────────────────────────

    private function rejected(int $status): RequestException
    {
        return new RequestException(new HttpResponse(new Psr7Response($status)));
    }

    private function queuedJob(array $drivers, ?AiTask $task = null): array
    {
        $task    ??= $this->makeTask();
        $payload = $task->toPayload();
        $ctx     = $task->context();
        $run     = AiRun::startAsQueue($drivers[0], $payload, $ctx, $task);

        $job = new ProcessAiPayload(
            driverName: $drivers[0],
            payload: $payload,
            context: $ctx,
            runId: $run->id,
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
            fallbackDrivers: array_slice($drivers, 1),
        );

        return [$job, $run];
    }

    public function test_should_try_next_only_skips_rejected_requests(): void
    {
        $this->assertTrue(Failover::shouldTryNext(ProviderConnectionException::forProvider('deepseek')));
        $this->assertTrue(Failover::shouldTryNext($this->rejected(500)));
        $this->assertTrue(Failover::shouldTryNext($this->rejected(408)));
        $this->assertTrue(Failover::shouldTryNext($this->rejected(429)));
        $this->assertTrue(Failover::shouldTryNext(new \RuntimeException('unknown')));
        $this->assertFalse(Failover::shouldTryNext($this->rejected(400)));
        $this->assertFalse(Failover::shouldTryNext($this->rejected(422)));
    }

    public function test_queued_job_falls_back_to_next_driver_on_connection_failure(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(ProviderConnectionException::forProvider('driverA')),
            'driverB' => $this->okDriver(),
        ]);

        [$job, $run] = $this->queuedJob(['driverA', 'driverB']);
        $job->handle(app(AiManager::class));

        $run->refresh();
        $this->assertSame('ok', $run->status);
        $this->assertSame('driverB', $run->driver, 'ai_runs.driver — той, що відповів');
    }

    public function test_queued_job_does_not_fall_back_when_request_is_rejected(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver($this->rejected(422)),
            'driverB' => $this->throwingDriver(new \RuntimeException('driverB must not be called')),
        ]);

        [$job, $run] = $this->queuedJob(['driverA', 'driverB']);

        try {
            $job->handle(app(AiManager::class));
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $e) {
            $this->assertSame(422, $e->response->status());
        }

        $run->refresh();
        $this->assertSame('running', $run->status, "'dead' ставить лише failed(), коли черга вичерпала спроби");
        $this->assertSame('driverA', $run->driver);
    }

    public function test_queued_job_rethrows_last_error_when_whole_chain_is_down(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(ProviderConnectionException::forProvider('driverA')),
            'driverB' => $this->throwingDriver(new \RuntimeException('driverB down too')),
        ]);

        [$job, $run] = $this->queuedJob(['driverA', 'driverB']);

        try {
            $job->handle(app(AiManager::class));
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('driverB down too', $e->getMessage());
        }

        $this->assertSame('driverB down too', $run->refresh()->error);
    }

    public function test_queue_passes_configured_routing_chain_to_job(): void
    {
        Queue::fake([ProcessAiPayload::class]);
        $this->swapDriverMap(['driverA' => $this->okDriver(), 'driverC' => $this->okDriver()]);

        AIFacade::queue($this->makeTask(), ['driverA', 'driverB', 'driverC']);

        Queue::assertPushed(ProcessAiPayload::class, fn (ProcessAiPayload $job): bool => $job->driverName === 'driverA'
            && $job->fallbackDrivers === ['driverC']);
    }

    public function test_queue_with_custom_key_does_not_fall_back(): void
    {
        Queue::fake([ProcessAiPayload::class]);
        $this->swapDriverMap(['driverA' => $this->okDriver(), 'driverB' => $this->okDriver()]);

        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', providerOverride: ['driver' => 'openai', 'key' => 'tenant-key']);
            }
        };

        AIFacade::queue($task, ['driverA', 'driverB']);

        Queue::assertPushed(ProcessAiPayload::class, fn (ProcessAiPayload $job): bool => $job->fallbackDrivers === []);
    }

    public function test_send_stops_chain_when_request_is_rejected(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver($this->rejected(400)),
            'driverB' => $this->throwingDriver(new \RuntimeException('driverB must not be called')),
        ]);

        try {
            AIFacade::send($this->makeTask(), ['driverA', 'driverB']);
            $this->fail('Expected AiDriverException was not thrown.');
        } catch (AiDriverException $e) {
            $this->assertInstanceOf(RequestException::class, $e->getPrevious());
        }

        $this->assertSame(1, AiRun::count());
    }

    public function test_stream_does_not_fall_back_after_output_started(): void
    {
        $partial = new class implements AiDriver {
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse { throw new \LogicException(); }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                $cb('Hel');
                throw ProviderConnectionException::forProvider('driverA');
            }
        };

        $this->swapDriverMap(['driverA' => $partial, 'driverB' => $this->okDriver()]);

        $chunks = [];

        try {
            AIFacade::stream($this->makeTask(), function (string $d) use (&$chunks): void { $chunks[] = $d; }, ['driverA', 'driverB']);
            $this->fail('Expected AiDriverException was not thrown.');
        } catch (AiDriverException $e) {
            $this->assertStringContainsString('after output started', $e->getMessage());
        }

        $this->assertSame(['Hel'], $chunks, 'другий драйвер не мав дописати свою відповідь до вже відданої частини');
    }

    public function test_stream_falls_back_when_nothing_was_streamed_yet(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(ProviderConnectionException::forProvider('driverA')),
            'driverB' => $this->okDriver(),
        ]);

        $resp = AIFacade::stream($this->makeTask(), fn () => null, ['driverA', 'driverB']);

        $this->assertTrue($resp->ok);
    }

    // ── onFailed ──────────────────────────────────────────────────────────

    private function recordingTask(): AiTask
    {
        $task = new class extends AiTask {
            public static array $failed = [];
            public static array $completed = [];
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function onFailed(\Throwable|string $reason): void { static::$failed[] = $reason; }
            public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void { static::$completed[] = $result; }
        };

        $task::$failed = [];
        $task::$completed = [];

        return $task;
    }

    public function test_queued_on_failed_is_called_once_after_all_retries(): void
    {
        Event::fake([AiRunFailed::class, AiTaskFailedFinally::class]);
        $this->swapDriverMap(['driverA' => $this->throwingDriver(ProviderConnectionException::forProvider('driverA'))]);

        $task = $this->recordingTask();
        [$job, $run] = $this->queuedJob(['driverA'], $task);

        foreach ([1, 2, 3] as $attempt) {
            try {
                $job->handle(app(AiManager::class));
            } catch (ProviderConnectionException $e) {
            }
        }

        $this->assertSame([], $task::$failed, 'проміжні спроби не є остаточним провалом');
        Event::assertNotDispatched(AiRunFailed::class);

        $job->failed($e);

        $this->assertCount(1, $task::$failed);
        $this->assertInstanceOf(ProviderConnectionException::class, $task::$failed[0]);
        $this->assertSame('dead', $run->refresh()->status);
        Event::assertDispatchedTimes(AiRunFailed::class, 1);
        Event::assertDispatchedTimes(AiTaskFailedFinally::class, 1);
    }

    public function test_queued_on_failed_is_called_for_not_ok_response(): void
    {
        $notOk = new class implements AiDriver {
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse { return new AiResponse(false, null, [], [], 'bad_output'); }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse { throw new \LogicException(); }
        };
        $this->swapDriverMap(['driverA' => $notOk]);

        $task = $this->recordingTask();
        [$job] = $this->queuedJob(['driverA'], $task);
        $job->handle(app(AiManager::class));

        $this->assertSame(['bad_output'], $task::$failed);
    }

    public function test_queued_on_failed_is_called_when_budget_exceeded(): void
    {
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);
        $this->swapDriverMap(['driverA' => $this->okDriver(cost: 1.0)]);

        $task = $this->recordingTask();
        [$job] = $this->queuedJob(['driverA'], $task);
        $job->handle(app(AiManager::class));

        $this->assertCount(1, $task::$failed);
        $this->assertInstanceOf(BudgetExceededException::class, $task::$failed[0]);
    }

    public function test_send_calls_on_failed_once_when_whole_chain_fails(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(new \RuntimeException('a')),
            'driverB' => $this->throwingDriver(new \RuntimeException('b')),
        ]);

        $task = $this->recordingTask();

        try {
            AIFacade::send($task, ['driverA', 'driverB']);
        } catch (AiDriverException $e) {
        }

        $this->assertCount(1, $task::$failed);
        $this->assertSame($e, $task::$failed[0]);
        $this->assertSame([], $task::$completed);
    }

    public function test_send_calls_on_failed_when_budget_already_exceeded_without_run(): void
    {
        Event::fake([AiTaskFailedFinally::class]);
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);
        AiRun::create([
            'tenant_id' => 'default', 'task' => 'prior', 'driver' => 'driverA', 'modality' => 'text',
            'dispatch' => 'sync', 'status' => 'ok', 'cost' => 1.0, 'request' => ['modality' => 'text'], 'finished_at' => now(),
        ]);
        $this->swapDriverMap(['driverA' => $this->okDriver()]);

        $task = $this->recordingTask();

        try {
            AIFacade::send($task, 'driverA');
        } catch (BudgetExceededException) {
        }

        $this->assertCount(1, $task::$failed);
        Event::assertDispatched(AiTaskFailedFinally::class, fn (AiTaskFailedFinally $e): bool => $e->run === null);
    }

    public function test_send_does_not_call_on_failed_when_fallback_succeeds(): void
    {
        $this->swapDriverMap([
            'driverA' => $this->throwingDriver(new \RuntimeException('a')),
            'driverB' => $this->okDriver(),
        ]);

        $task = $this->recordingTask();
        AIFacade::send($task, ['driverA', 'driverB']);

        $this->assertSame([], $task::$failed);
        $this->assertCount(1, $task::$completed);
    }

    public function test_throwing_on_failed_does_not_replace_the_original_error(): void
    {
        $this->swapDriverMap(['driverA' => $this->throwingDriver(new \RuntimeException('boom'))]);

        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function onFailed(\Throwable|string $reason): void { throw new \LogicException('hook broke'); }
        };

        $this->expectException(AiDriverException::class);
        $this->expectExceptionMessage('boom');

        AIFacade::send($task, 'driverA');
    }
}
