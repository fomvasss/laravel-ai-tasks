<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class ProviderOverrideTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->artisan('migrate');
    }

    private function makeTask(?array $providerOverride = null): AiTask
    {
        return new class($providerOverride) extends AiTask {
            public function __construct(private ?array $override) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload
            {
                return new AiPayload(
                    modality: 'text',
                    messages: [new UserMessage('hello')],
                    providerOverride: $this->override,
                );
            }
        };
    }

    // ── AiPayload ─────────────────────────────────────────────────────────

    public function test_provider_override_defaults_to_null(): void
    {
        $payload = new AiPayload('text');

        $this->assertNull($payload->providerOverride);
    }

    public function test_provider_override_is_stored(): void
    {
        $override = ['driver' => 'openai', 'key' => 'sk-test-123'];
        $payload  = new AiPayload('text', providerOverride: $override);

        $this->assertSame($override, $payload->providerOverride);
    }

    public function test_provider_override_does_not_affect_other_fields(): void
    {
        $payload = new AiPayload(
            modality: 'text',
            systemPrompt: 'Be helpful.',
            options: ['temperature' => 0.3],
            jsonMode: true,
            providerOverride: ['driver' => 'deepseek', 'key' => 'sk-x'],
        );

        $this->assertSame('text', $payload->modality);
        $this->assertSame('Be helpful.', $payload->systemPrompt);
        $this->assertSame(0.3, $payload->options['temperature']);
        $this->assertTrue($payload->jsonMode);
        $this->assertSame('deepseek', $payload->providerOverride['driver']);
    }

    // ── LaravelAiDriver::resolveProvider ──────────────────────────────────

    public function test_resolve_provider_returns_own_provider_when_no_override(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);
        $payload = new AiPayload('text');

        $result = $this->callResolveProvider($driver, $payload);

        $this->assertSame('openai', $result);
    }

    public function test_resolve_provider_returns_own_provider_when_override_has_no_key(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);
        $payload = new AiPayload('text', providerOverride: ['driver' => 'deepseek']);

        $result = $this->callResolveProvider($driver, $payload);

        $this->assertSame('openai', $result);
    }

    public function test_resolve_provider_registers_config_and_returns_alias(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);
        $payload = new AiPayload('text', providerOverride: [
            'driver' => 'deepseek',
            'key'    => 'sk-custom-abc',
        ]);

        $alias = $this->callResolveProvider($driver, $payload);

        $this->assertStringStartsWith('custom_', $alias);
        $this->assertSame('deepseek',       config("ai.providers.{$alias}.driver"));
        $this->assertSame('sk-custom-abc',  config("ai.providers.{$alias}.key"));
    }

    public function test_resolve_provider_alias_is_deterministic(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);
        $payload = new AiPayload('text', providerOverride: ['driver' => 'openai', 'key' => 'sk-123']);

        $alias1 = $this->callResolveProvider($driver, $payload);
        $alias2 = $this->callResolveProvider($driver, $payload);

        $this->assertSame($alias1, $alias2);
    }

    public function test_resolve_provider_different_keys_produce_different_aliases(): void
    {
        $driver = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);

        $alias1 = $this->callResolveProvider($driver, new AiPayload('text', providerOverride: ['driver' => 'openai', 'key' => 'sk-aaa']));
        $alias2 = $this->callResolveProvider($driver, new AiPayload('text', providerOverride: ['driver' => 'openai', 'key' => 'sk-bbb']));

        $this->assertNotSame($alias1, $alias2);
    }

    public function test_resolve_provider_stores_organization_in_config(): void
    {
        $driver  = new LaravelAiDriver('openai', ['model' => 'gpt-4o']);
        $payload = new AiPayload('text', providerOverride: [
            'driver'       => 'openai',
            'key'          => 'sk-key',
            'organization' => 'org-123',
        ]);

        $alias = $this->callResolveProvider($driver, $payload);

        $this->assertSame('org-123', config("ai.providers.{$alias}.organization"));
    }

    // ── AI::send — bypass isConfigured when override key present ─────────

    public function test_send_skips_driver_without_config_key_when_no_override(): void
    {
        // driver є в ai-tasks але немає ключа в ai.providers → isConfigured = false
        config(['ai-tasks.drivers.my_ai' => ['model' => 'some-model']]);

        $this->expectException(AiDriverException::class);

        AIFacade::send($this->makeTask(null), 'my_ai');
    }

    public function test_send_with_null_driver_and_override_does_not_crash(): void
    {
        config(['ai-tasks.drivers.null' => []]);

        $resp = AIFacade::send(
            $this->makeTask(['driver' => 'openai', 'key' => 'sk-fake']),
            'null',
        );

        $this->assertTrue($resp->ok);
    }

    // ── payloadWithTools preserves providerOverride ───────────────────────

    public function test_payload_with_tools_preserves_provider_override(): void
    {
        $override = ['driver' => 'anthropic', 'key' => 'sk-ant-test'];

        $task = new class($override) extends AiTask {
            public function __construct(private array $override) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', providerOverride: $this->override);
            }
            public function tools(): array
            {
                return [fn() => 'dummy'];
            }
        };

        $ai     = app(AI::class);
        $method = new \ReflectionMethod($ai, 'payloadWithTools');
        $method->setAccessible(true);

        $payload = $method->invoke($ai, $task);

        $this->assertSame($override, $payload->providerOverride);
        $this->assertNotEmpty($payload->tools);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function callResolveProvider(LaravelAiDriver $driver, AiPayload $payload): string
    {
        $method = new \ReflectionMethod($driver, 'resolveProvider');
        $method->setAccessible(true);

        return $method->invoke($driver, $payload);
    }
}
