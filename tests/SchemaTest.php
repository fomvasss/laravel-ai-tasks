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
use Fomvasss\AiTasks\Drivers\JsonModeAgent;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\SerializableClosure\SerializableClosure;
use Orchestra\Testbench\TestCase;

class SchemaTest extends TestCase
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

    private function makeSchema(): \Closure
    {
        return fn (JsonSchema $schema): array => ['category' => $schema->string()];
    }

    private function makeTask(?\Closure $schema = null): AiTask
    {
        return new class($schema) extends AiTask {
            public function __construct(private ?\Closure $taskSchema) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function schema(): ?\Closure { return $this->taskSchema; }
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

    // ── Unit: AiTask::schema() ────────────────────────────────────────────

    public function test_task_schema_defaults_to_null(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertNull($task->schema());
    }

    public function test_task_can_declare_schema(): void
    {
        $schema = $this->makeSchema();
        $task   = $this->makeTask($schema);

        $this->assertSame($schema, $task->schema());
    }

    // ── Unit: AiPayload::schema ───────────────────────────────────────────

    public function test_payload_defaults_schema_to_null(): void
    {
        $this->assertNull((new AiPayload('text'))->schema);
    }

    public function test_payload_wraps_schema_in_serializable_closure(): void
    {
        $schema  = $this->makeSchema();
        $payload = new AiPayload('text', schema: $schema);

        $this->assertInstanceOf(SerializableClosure::class, $payload->schema);
        $this->assertSame($schema, $payload->schema->getClosure());
    }

    // ── Unit: LaravelAiDriver agent selection ─────────────────────────────

    public function test_driver_uses_structured_agent_when_schema_present(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text', schema: $this->makeSchema());

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(StructuredAnonymousAgent::class, $agent);
    }

    public function test_driver_uses_json_mode_agent_when_no_schema(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text', jsonMode: true);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(JsonModeAgent::class, $agent);
    }

    public function test_driver_uses_plain_agent_by_default(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text');

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertNotInstanceOf(StructuredAnonymousAgent::class, $agent);
        $this->assertNotInstanceOf(JsonModeAgent::class, $agent);
        $this->assertInstanceOf(AnonymousAgent::class, $agent);
    }

    public function test_schema_takes_precedence_over_json_mode(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $payload = new AiPayload('text', jsonMode: true, schema: $this->makeSchema());

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertInstanceOf(StructuredAnonymousAgent::class, $agent);
    }

    // ── Integration: schema flows through AI::send() ─────────────────────

    public function test_send_passes_schema_to_driver(): void
    {
        $schema = $this->makeSchema();

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

        AIFacade::send($this->makeTask($schema), 'null');

        $this->assertNotNull($spy->received);
        $this->assertNotNull($spy->received->schema);
        $this->assertSame($schema, $spy->received->schema->getClosure());
    }

    public function test_send_without_schema_passes_null_schema(): void
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

        $this->assertNull($spy->received->schema);
    }
}
