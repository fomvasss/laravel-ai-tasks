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
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Orchestra\Testbench\TestCase;

class ToolsTest extends TestCase
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

    private function makeTool(): Tool
    {
        return new class implements Tool {
            public function description(): string { return 'A test tool'; }
            public function handle(Request $request): string { return 'result'; }
            public function schema(JsonSchema $schema): array { return []; }
        };
    }

    private function makeTask(array $tools = []): AiTask
    {
        return new class($tools) extends AiTask {
            public function __construct(private array $taskTools) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function tools(): array { return $this->taskTools; }
        };
    }

    /**
     * Swap AiManager with an anonymous subclass that always returns $driver,
     * and re-bind the AI singleton so it sees the new manager.
     */
    private function swapDriverForNull(AiDriver $driver): void
    {
        $manager = new class(app(), $driver) extends AiManager {
            public function __construct($app, private AiDriver $spy) { parent::__construct($app); }
            protected function createDriver($name): AiDriver { return $this->spy; }
        };

        app()->instance(AiManager::class, $manager);
        app()->instance(AI::class, new AI($manager, app(Router::class)));
    }

    // ── Unit: AiTask::tools() ─────────────────────────────────────────────

    public function test_task_tools_defaults_to_empty(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertSame([], $task->tools());
    }

    public function test_task_can_declare_tools(): void
    {
        $tool = $this->makeTool();
        $task = $this->makeTask([$tool]);

        $this->assertCount(1, $task->tools());
        $this->assertSame($tool, $task->tools()[0]);
    }

    // ── Unit: AiPayload::tools ────────────────────────────────────────────

    public function test_payload_defaults_tools_to_empty(): void
    {
        $this->assertSame([], (new AiPayload('text'))->tools);
    }

    public function test_payload_stores_tools(): void
    {
        $tool    = $this->makeTool();
        $payload = new AiPayload('text', [], null, [], [], [$tool]);

        $this->assertCount(1, $payload->tools);
        $this->assertSame($tool, $payload->tools[0]);
    }

    // ── Integration: tools flow through AI::send() ───────────────────────

    public function test_send_passes_tools_to_driver(): void
    {
        $tool     = $this->makeTool();
        $captured = null;

        $spy = new class($captured) implements AiDriver {
            public ?AiPayload $received = null;
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse
            {
                $this->received = $p;
                return new AiResponse(true, 'ok');
            }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                return new AiResponse(true, 'ok');
            }
        };

        $this->swapDriverForNull($spy);

        AIFacade::send($this->makeTask([$tool]), 'null');

        $this->assertNotNull($spy->received);
        $this->assertCount(1, $spy->received->tools);
        $this->assertSame($tool, $spy->received->tools[0]);
    }

    public function test_stream_passes_tools_to_driver(): void
    {
        $tool = $this->makeTool();

        $spy = new class implements AiDriver {
            public ?AiPayload $received = null;
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse
            {
                return new AiResponse(true, 'ok');
            }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                $this->received = $p;
                $cb('ok');
                return new AiResponse(true, 'ok');
            }
        };

        $this->swapDriverForNull($spy);

        AIFacade::stream($this->makeTask([$tool]), fn($c) => null, 'null');

        $this->assertNotNull($spy->received);
        $this->assertCount(1, $spy->received->tools);
        $this->assertSame($tool, $spy->received->tools[0]);
    }

    public function test_send_without_tools_passes_empty_tools(): void
    {
        $spy = new class implements AiDriver {
            public ?AiPayload $received = null;
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse
            {
                $this->received = $p;
                return new AiResponse(true, 'ok');
            }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                return new AiResponse(true, 'ok');
            }
        };

        $this->swapDriverForNull($spy);

        AIFacade::send($this->makeTask([]), 'null');

        $this->assertSame([], $spy->received->tools);
    }

    public function test_multiple_tools_all_reach_driver(): void
    {
        $tools = [$this->makeTool(), $this->makeTool(), $this->makeTool()];

        $spy = new class implements AiDriver {
            public ?AiPayload $received = null;
            public function supports(string $modality): bool { return true; }
            public function send(AiPayload $p, AiContext $c): AiResponse
            {
                $this->received = $p;
                return new AiResponse(true, 'ok');
            }
            public function stream(AiPayload $p, AiContext $c, callable $cb): AiResponse
            {
                return new AiResponse(true, 'ok');
            }
        };

        $this->swapDriverForNull($spy);

        AIFacade::send($this->makeTask($tools), 'null');

        $this->assertCount(3, $spy->received->tools);
        $this->assertSame($tools, $spy->received->tools);
    }
}
