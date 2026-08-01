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
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Fomvasss\AiTasks\Events\AiTaskCompletedHandlerFailed;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class OnCompletedTest extends TestCase
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

    private function recordingTask(): AiTask
    {
        $task = new class extends AiTask {
            public static array $calls = [];
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
            {
                static::$calls[] = $attemptsExhausted;
            }
        };
        $task::$calls = [];

        return $task;
    }

    private function throwingHookTask(): AiTask
    {
        return new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
            {
                throw new \RuntimeException('hook boom');
            }
        };
    }

    private function retryableRecordingTask(int $maxRetries, bool $acceptable): AiTask
    {
        $task = new class($maxRetries, $acceptable) extends AiTask {
            public static array $calls = [];
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
            public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
            {
                static::$calls[] = $attemptsExhausted;
            }
        };
        $task::$calls = [];

        return $task;
    }

    private function okDriver(): AiDriver
    {
        return new class implements AiDriver {
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse { return new AiResponse(true, 'ok'); }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse { $cb('ok'); return new AiResponse(true, 'ok'); }
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

    private function makeOkRun(AiTask $task): AiRun
    {
        $run = AiRun::startAsQueue('driverA', $task->toPayload(), $task->context(), $task);
        $run->update(['status' => 'ok', 'response' => ['content' => 'irrelevant']]);

        return $run;
    }

    // ── send() / stream() ────────────────────────────────────────────────

    public function test_send_calls_on_completed_once_with_attempts_exhausted_false(): void
    {
        $this->swapDriverMap(['driverA' => $this->okDriver()]);

        $task = $this->recordingTask();
        AIFacade::send($task, 'driverA');

        $this->assertSame([false], $task::$calls);
    }

    public function test_stream_calls_on_completed(): void
    {
        $this->swapDriverMap(['driverA' => $this->okDriver()]);

        $task = $this->recordingTask();
        AIFacade::stream($task, fn ($c) => null, 'driverA');

        $this->assertSame([false], $task::$calls);
    }

    // ── PostprocessAiResult (queued path) ────────────────────────────────

    public function test_queue_path_calls_on_completed_once_when_accepted(): void
    {
        $task = $this->retryableRecordingTask(maxRetries: 1, acceptable: true);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        $this->assertSame([false], $task::$calls);
    }

    public function test_queue_path_does_not_call_on_completed_on_intermediate_rejected_attempt(): void
    {
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->retryableRecordingTask(maxRetries: 1, acceptable: false);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        $this->assertSame([], $task::$calls);
    }

    public function test_queue_path_calls_on_completed_with_attempts_exhausted_true(): void
    {
        Queue::fake([ProcessAiPayload::class]);

        $task = $this->retryableRecordingTask(maxRetries: 1, acceptable: false);
        $run  = $this->makeOkRun($task);

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 1))->handle();

        $this->assertSame([true], $task::$calls);
    }

    // ── Failure isolation ─────────────────────────────────────────────────

    public function test_exception_in_on_completed_is_caught_fires_handler_failed_and_still_fires_completed(): void
    {
        Event::fake([AiTaskCompleted::class, AiTaskCompletedHandlerFailed::class]);
        $this->swapDriverMap(['driverA' => $this->okDriver()]);

        $task = $this->throwingHookTask();
        $resp = AIFacade::send($task, 'driverA');

        $this->assertTrue($resp->ok);
        Event::assertDispatched(AiTaskCompletedHandlerFailed::class, fn (AiTaskCompletedHandlerFailed $e): bool => $e->task === $task && $e->exception->getMessage() === 'hook boom');
        Event::assertDispatched(AiTaskCompleted::class);
    }
}
