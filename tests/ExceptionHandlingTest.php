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
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
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

    public function test_send_rethrows_and_marks_failed_on_budget_exceeded(): void
    {
        config(['ai-tasks.budgets.default.monthly_usd' => 0.0]);

        $this->swapDriverMap(['driverA' => $this->okDriver(cost: 10.0)]);

        $this->expectException(\Fomvasss\AiTasks\Exceptions\BudgetExceededException::class);

        try {
            AIFacade::send($this->makeTask(), 'driverA');
        } finally {
            $run = AiRun::query()->latest('created_at')->first();
            $this->assertSame('error', $run->status);
            $this->assertNotNull($run->finished_at);
        }
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
}
