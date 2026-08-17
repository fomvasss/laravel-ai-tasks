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
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Orchestra\Testbench\TestCase;

class DecisionsTest extends TestCase
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

    private function swapDriverForSpy(AiDriver $driver): void
    {
        $manager = new class(app(), $driver) extends AiManager {
            public function __construct($app, private AiDriver $spy) { parent::__construct($app); }
            protected function createDriver($name): AiDriver { return $this->spy; }
        };

        app()->instance(AiManager::class, $manager);
        app()->instance(AI::class, new AI($manager, app(Router::class)));
    }

    private function makeTask(Decisions|array|null $decisions = null): AiTask
    {
        return new class($decisions) extends AiTask {
            public function __construct(private readonly Decisions|array|null $decisions) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', messages: [new UserMessage('hi')], decisions: $this->decisions);
            }
        };
    }

    private function callBuildTextResponse(LaravelAiDriver $driver, AgentResponse $response): AiResponse
    {
        $method = new \ReflectionMethod($driver, 'buildTextResponse');
        $method->setAccessible(true);

        return $method->invoke($driver, $response, 'openai', 'gpt-4o-mini');
    }

    // ── Unit: AiPayload::decisions coercion ─────────────────────────────────

    public function test_payload_defaults_decisions_to_null(): void
    {
        $this->assertNull((new AiPayload('text'))->decisions);
    }

    public function test_payload_coerces_decision_map(): void
    {
        $payload = new AiPayload('text', decisions: ['call_abc' => true, 'call_def' => Decision::reject('nope')]);

        $this->assertInstanceOf(Decisions::class, $payload->decisions);
        $this->assertTrue($payload->decisions->get('call_abc')->isApproved());
        $this->assertTrue($payload->decisions->get('call_def')->isRejected());
    }

    public function test_payload_accepts_decisions_instance(): void
    {
        $decisions = Decisions::from(['call_abc' => true]);
        $payload   = new AiPayload('text', decisions: $decisions);

        $this->assertSame($decisions, $payload->decisions);
    }

    // ── Unit: LaravelAiDriver::buildTextResponse() pendingApprovals mapping ─

    public function test_build_text_response_maps_pending_approvals(): void
    {
        $driver   = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $response = AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_abc', 'order_create', ['qty' => 2], 'Real order requires confirmation.'),
        ]);

        $aiResponse = $this->callBuildTextResponse($driver, $response);

        $this->assertCount(1, $aiResponse->pendingApprovals);
        $this->assertSame('call_abc', $aiResponse->pendingApprovals[0]['id']);
        $this->assertSame('order_create', $aiResponse->pendingApprovals[0]['tool']);
        $this->assertSame(['qty' => 2], $aiResponse->pendingApprovals[0]['arguments']);
        $this->assertSame('Real order requires confirmation.', $aiResponse->pendingApprovals[0]['reason']);
    }

    public function test_build_text_response_defaults_pending_approvals_to_empty(): void
    {
        $driver     = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $response   = new AgentResponse('inv_1', 'ok', new Usage, new Meta);
        $aiResponse = $this->callBuildTextResponse($driver, $response);

        $this->assertSame([], $aiResponse->pendingApprovals);
    }

    // ── Integration: decisions flows through AI::send() ─────────────────────

    public function test_send_passes_decisions_to_driver(): void
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

        $this->swapDriverForSpy($spy);

        AIFacade::send($this->makeTask(['call_abc' => true]), 'null');

        $this->assertNotNull($spy->received->decisions);
        $this->assertTrue($spy->received->decisions->get('call_abc')->isApproved());
    }

    public function test_payload_with_tools_preserves_decisions(): void
    {
        // Regression: AI::payloadWithTools() rebuilds AiPayload to merge tools()/schema()/
        // toolChoice() and used to silently drop decisions back to null in that rebuild —
        // only caught by combining tools() with decisions in the same task, which the
        // early-return branch (no tools/schema/toolChoice) doesn't exercise.
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function tools(): array { return [new class implements \Laravel\Ai\Contracts\Tool {
                public function name(): string { return 'noop'; }
                public function description(): string { return 'noop'; }
                public function handle(\Laravel\Ai\Tools\Request $request): string { return 'ok'; }
                public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array { return []; }
            }]; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', decisions: ['call_abc' => true]);
            }
        };

        $payload = AI::payloadWithTools($task);

        $this->assertNotEmpty($payload->tools);
        $this->assertNotNull($payload->decisions);
        $this->assertTrue($payload->decisions->get('call_abc')->isApproved());
    }

    public function test_send_without_decisions_passes_null(): void
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

        $this->swapDriverForSpy($spy);

        AIFacade::send($this->makeTask(), 'null');

        $this->assertNull($spy->received->decisions);
    }

    // ── Integration: pendingApprovals survive the queued path ──────────────

    public function test_queue_path_preserves_pending_approvals_through_postprocess(): void
    {
        // Regression: AiRun::finish() never persisted pendingApprovals into `response`, and
        // PostprocessAiResult::handle() never reconstructed it from the stored run — a pause
        // dispatched via AI::queue() silently lost $response->pendingApprovals by the time
        // postprocess()/onCompleted() ran, always seeing an empty array instead.
        $task = new class extends AiTask {
            public static ?AiResponse $seen = null;
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function postprocess(AiResponse $response): AiResponse|array
            {
                static::$seen = $response;
                return $response;
            }
        };

        $run = AiRun::startAsQueue('driverA', $task->toPayload(), $task->context(), $task);
        $run->finish(new AiResponse(
            ok: true,
            content: 'Order preview: 2x Widget, $40 total. Confirm?',
            pendingApprovals: [
                ['id' => 'call_abc', 'tool' => 'order-create', 'arguments' => ['qty' => 2], 'reason' => null],
            ],
        ));

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        $this->assertNotEmpty($task::$seen->pendingApprovals);
        $this->assertSame('call_abc', $task::$seen->pendingApprovals[0]['id']);
        $this->assertSame('order-create', $task::$seen->pendingApprovals[0]['tool']);
    }
}
