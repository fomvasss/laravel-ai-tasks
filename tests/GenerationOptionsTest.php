<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Orchestra\Testbench\TestCase;

class GenerationOptionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    private function callMakeAgent(AiPayload $payload): AnonymousAgent
    {
        $driver = new LaravelAiDriver('openai', ['model' => 'gpt-4o-mini']);
        $method = new \ReflectionMethod($driver, 'makeAgent');

        return $method->invoke($driver, $payload, []);
    }

    public function test_temperature_from_options_reaches_agent(): void
    {
        $agent = $this->callMakeAgent(new AiPayload('text', options: ['temperature' => 0.2]));

        $this->assertSame(0.2, $agent->temperature());
    }

    public function test_max_tokens_and_top_p_from_options_reach_agent(): void
    {
        $agent = $this->callMakeAgent(new AiPayload('text', options: ['max_tokens' => 512, 'top_p' => 0.9]));

        $this->assertSame(512, $agent->maxTokens());
        $this->assertSame(0.9, $agent->topP());
    }

    public function test_options_default_to_null_when_not_set(): void
    {
        $agent = $this->callMakeAgent(new AiPayload('text'));

        $this->assertNull($agent->temperature());
        $this->assertNull($agent->maxTokens());
        $this->assertNull($agent->topP());
    }

    public function test_laravel_ai_resolves_temperature_from_agent(): void
    {
        $agent = $this->callMakeAgent(new AiPayload('text', options: ['temperature' => 0.7]));

        $options = TextGenerationOptions::forAgent($agent);

        $this->assertSame(0.7, $options->temperature);
    }

    public function test_json_mode_agent_also_carries_generation_options(): void
    {
        $agent = $this->callMakeAgent(new AiPayload('text', options: ['temperature' => 0.1], jsonMode: true));

        $this->assertSame(0.1, $agent->temperature());
    }
}
