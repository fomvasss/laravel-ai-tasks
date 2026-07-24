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
use Fomvasss\AiTasks\Drivers\AnonymousToolChoiceAgent;
use Fomvasss\AiTasks\Drivers\JsonModeAgent;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Drivers\StructuredToolChoiceAgent;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\ToolChoice;
use Orchestra\Testbench\TestCase;

class ToolChoiceTest extends TestCase
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

    private function makeTask(ToolChoice|string|array|null $toolChoice = null, bool $jsonMode = false, ?\Closure $schema = null): AiTask
    {
        return new class($toolChoice, $jsonMode, $schema) extends AiTask {
            public function __construct(
                private readonly ToolChoice|string|array|null $tc,
                private readonly bool $jsonMode,
                private readonly ?\Closure $taskSchema,
            ) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', jsonMode: $this->jsonMode); }
            public function schema(): ?\Closure { return $this->taskSchema; }
            public function toolChoice(): ToolChoice|string|array|null { return $this->tc; }
        };
    }

    private function swapDriverForNull(AiDriver $driver): void
    {
        $manager = new class(app(), $driver) extends AiManager {
            public function __construct($app, private AiDriver $spy) { parent::__construct($app); }
            protected function createDriver($name): AiDriver { return $this->spy; }
        };

        app()->instance(AiManager::class, $manager);
        app()->instance(AI::class, new AI($manager, app(Router::class)));
    }

    private function callMakeAgent(LaravelAiDriver $driver, AiPayload $payload): AnonymousAgent
    {
        $method = new \ReflectionMethod($driver, 'makeAgent');
        $method->setAccessible(true);

        return $method->invoke($driver, $payload, []);
    }

    // ── Unit: AiTask::toolChoice() ────────────────────────────────────────

    public function test_task_tool_choice_defaults_to_null(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertNull($task->toolChoice());
    }

    // ── Unit: AiPayload::toolChoice ───────────────────────────────────────

    public function test_payload_defaults_tool_choice_to_null(): void
    {
        $this->assertNull((new AiPayload('text'))->toolChoice);
    }

    public function test_payload_coerces_string_mode(): void
    {
        $payload = new AiPayload('text', toolChoice: 'required');

        $this->assertInstanceOf(ToolChoice::class, $payload->toolChoice);
        $this->assertSame(ToolChoice::required, $payload->toolChoice->mode);
    }

    public function test_payload_coerces_tool_name_array(): void
    {
        $payload = new AiPayload('text', toolChoice: ['tool' => 'current_date']);

        $this->assertSame(ToolChoice::tool, $payload->toolChoice->mode);
        $this->assertSame('current_date', $payload->toolChoice->toolName);
    }

    public function test_payload_accepts_tool_choice_instance(): void
    {
        $tc      = ToolChoice::tool('current_date');
        $payload = new AiPayload('text', toolChoice: $tc);

        $this->assertSame($tc, $payload->toolChoice);
    }

    // ── Unit: LaravelAiDriver agent selection ─────────────────────────────

    public function test_driver_uses_anonymous_tool_choice_agent_by_default_with_tool_choice(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text', toolChoice: ToolChoice::required);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(AnonymousToolChoiceAgent::class, $agent);
        $this->assertSame(ToolChoice::required, $agent->toolChoice()->mode);
    }

    public function test_driver_uses_plain_agent_when_no_tool_choice(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text');

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertNotInstanceOf(AnonymousToolChoiceAgent::class, $agent);
        $this->assertInstanceOf(AnonymousAgent::class, $agent);
    }

    public function test_driver_applies_tool_choice_to_json_mode_agent(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text', jsonMode: true, toolChoice: ToolChoice::none);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(JsonModeAgent::class, $agent);
        $this->assertSame(ToolChoice::none, $agent->toolChoice()->mode);
    }

    public function test_driver_applies_tool_choice_to_structured_agent(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $schema  = fn (JsonSchema $s): array => ['category' => $s->string()];
        $payload = new AiPayload('text', schema: $schema, toolChoice: ToolChoice::tool('output_structured_data'));

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(StructuredToolChoiceAgent::class, $agent);
        $this->assertSame(ToolChoice::tool, $agent->toolChoice()->mode);
    }

    // ── Integration: toolChoice flows through AI::send() ──────────────────

    public function test_send_passes_tool_choice_to_driver(): void
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

        AIFacade::send($this->makeTask(ToolChoice::required), 'null');

        $this->assertNotNull($spy->received->toolChoice);
        $this->assertSame(ToolChoice::required, $spy->received->toolChoice->mode);
    }

    public function test_send_without_tool_choice_passes_null(): void
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

        AIFacade::send($this->makeTask(), 'null');

        $this->assertNull($spy->received->toolChoice);
    }
}
