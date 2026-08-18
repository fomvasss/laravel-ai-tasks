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

    private function callMakeAgent(AiPayload $payload, string $displayProvider = 'openai', string $model = 'gpt-4o-mini'): AnonymousAgent
    {
        $driver = new LaravelAiDriver($displayProvider, ['model' => $model]);
        $method = new \ReflectionMethod($driver, 'makeAgent');

        return $method->invoke($driver, $payload, [], $displayProvider, $model);
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

    // ── OpenAI reasoning models reject temperature/top_p ─────────────────────

    public function test_strips_temperature_and_top_p_for_openai_reasoning_model(): void
    {
        // Regression: OpenAI's reasoning models (gpt-5* except gpt-5-chat, o1, o3, o4-mini)
        // reject `temperature`/`top_p` with a 400 ("Unsupported parameter") — laravel/ai's
        // own OpenAI gateway doesn't filter these out itself.
        $agent = $this->callMakeAgent(
            new AiPayload('text', options: ['temperature' => 0.3, 'top_p' => 0.9]),
            model: 'gpt-5.6-luna',
        );

        $this->assertNull($agent->temperature());
        $this->assertNull($agent->topP());
    }

    public function test_strips_temperature_for_o1_o3_o4_mini_models(): void
    {
        foreach (['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini'] as $model) {
            $agent = $this->callMakeAgent(new AiPayload('text', options: ['temperature' => 0.3]), model: $model);

            $this->assertNull($agent->temperature(), "expected null temperature for model [{$model}]");
        }
    }

    public function test_keeps_temperature_for_gpt5_chat_model(): void
    {
        // gpt-5-chat is explicitly excluded from the reasoning family.
        $agent = $this->callMakeAgent(
            new AiPayload('text', options: ['temperature' => 0.5]),
            model: 'gpt-5-chat-latest',
        );

        $this->assertSame(0.5, $agent->temperature());
    }

    public function test_keeps_temperature_for_non_openai_provider_even_with_gpt5_like_model_name(): void
    {
        // The gate is provider-scoped, not just a model-name pattern match — an unrelated
        // provider happening to use a similarly-named model must not lose temperature.
        $agent = $this->callMakeAgent(
            new AiPayload('text', options: ['temperature' => 0.7]),
            displayProvider: 'deepseek',
            model: 'deepseek-v4-flash',
        );

        $this->assertSame(0.7, $agent->temperature());
    }
}
