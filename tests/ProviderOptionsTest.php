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
use Fomvasss\AiTasks\Drivers\StructuredToolChoiceAgent;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Orchestra\Testbench\TestCase;

class ProviderOptionsTest extends TestCase
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

    private function makeTask(array $options = []): AiTask
    {
        return new class($options) extends AiTask {
            public function __construct(private readonly array $opts) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', options: $this->opts); }
            public function schema(): ?\Closure { return fn (JsonSchema $s): array => ['category' => $s->string()]; }
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

    private function callMakeAgent(LaravelAiDriver $driver, AiPayload $payload): StructuredToolChoiceAgent
    {
        $method = new \ReflectionMethod($driver, 'makeAgent');
        $method->setAccessible(true);

        return $method->invoke($driver, $payload, []);
    }

    // ── Unit: LaravelAiDriver → StructuredToolChoiceAgent ──────────────────

    public function test_driver_passes_provider_options_to_structured_agent(): void
    {
        $driver  = new LaravelAiDriver('deepseek', ['model' => 'deepseek-v4-flash']);
        $schema  = fn (JsonSchema $s): array => ['category' => $s->string()];
        $payload = new AiPayload('text', schema: $schema, options: [
            'provider_options' => ['deepseek' => ['thinking' => ['type' => 'disabled']]],
        ]);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertSame(['thinking' => ['type' => 'disabled']], $agent->providerOptions('deepseek'));
    }

    public function test_structured_agent_provider_options_default_to_empty_array(): void
    {
        $driver  = new LaravelAiDriver('deepseek', ['model' => 'deepseek-v4-flash']);
        $schema  = fn (JsonSchema $s): array => ['category' => $s->string()];
        $payload = new AiPayload('text', schema: $schema);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertSame([], $agent->providerOptions('deepseek'));
    }

    public function test_structured_agent_provider_options_are_scoped_by_driver_key(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $schema  = fn (JsonSchema $s): array => ['category' => $s->string()];
        $payload = new AiPayload('text', schema: $schema, options: [
            'provider_options' => ['deepseek' => ['thinking' => ['type' => 'disabled']]],
        ]);

        $agent = $this->callMakeAgent($driver, $payload);

        $this->assertSame([], $agent->providerOptions('openai'));
        $this->assertSame(['thinking' => ['type' => 'disabled']], $agent->providerOptions('deepseek'));
    }

    // ── Integration: provider_options flow through AI::send() ─────────────

    public function test_send_passes_provider_options_through_to_payload(): void
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

        AIFacade::send($this->makeTask(['provider_options' => ['deepseek' => ['thinking' => ['type' => 'disabled']]]]), 'null');

        $this->assertSame(
            ['deepseek' => ['thinking' => ['type' => 'disabled']]],
            $spy->received->options['provider_options'],
        );
    }
}
