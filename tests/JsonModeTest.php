<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Drivers\JsonModeAgent;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Orchestra\Testbench\TestCase;

class JsonModeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    // ── AiPayload ─────────────────────────────────────────────────────────

    public function test_json_mode_defaults_to_false(): void
    {
        $payload = new AiPayload('text');

        $this->assertFalse($payload->jsonMode);
    }

    public function test_json_mode_can_be_set_to_true(): void
    {
        $payload = new AiPayload('text', jsonMode: true);

        $this->assertTrue($payload->jsonMode);
    }

    public function test_json_mode_does_not_affect_other_fields(): void
    {
        $payload = new AiPayload(
            modality: 'text',
            systemPrompt: 'You are helpful.',
            options: ['temperature' => 0.5],
            jsonMode: true,
        );

        $this->assertSame('text', $payload->modality);
        $this->assertSame('You are helpful.', $payload->systemPrompt);
        $this->assertSame(0.5, $payload->options['temperature']);
        $this->assertTrue($payload->jsonMode);
    }

    // ── JsonModeAgent ─────────────────────────────────────────────────────

    public function test_json_mode_agent_extends_anonymous_agent(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $this->assertInstanceOf(AnonymousAgent::class, $agent);
    }

    public function test_json_mode_agent_implements_has_provider_options(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $this->assertInstanceOf(HasProviderOptions::class, $agent);
    }

    // OpenAI і xAI — Responses API, text.format

    public function test_json_mode_agent_returns_text_format_for_openai(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::OpenAI);

        $this->assertSame(['text' => ['format' => ['type' => 'json_object']]], $options);
    }

    public function test_json_mode_agent_returns_text_format_for_xai(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::xAI);

        $this->assertSame(['text' => ['format' => ['type' => 'json_object']]], $options);
    }

    // Gemini — response_mime_type у generationConfig

    public function test_json_mode_agent_returns_mime_type_for_gemini(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::Gemini);

        $this->assertSame(['response_mime_type' => 'application/json'], $options);
    }

    // Chat Completions-сумісні провайдери — response_format

    public function test_json_mode_agent_returns_response_format_for_deepseek(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::DeepSeek);

        $this->assertSame(['response_format' => ['type' => 'json_object']], $options);
    }

    public function test_json_mode_agent_returns_response_format_for_groq(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::Groq);

        $this->assertSame(['response_format' => ['type' => 'json_object']], $options);
    }

    public function test_json_mode_agent_returns_response_format_for_mistral(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::Mistral);

        $this->assertSame(['response_format' => ['type' => 'json_object']], $options);
    }

    public function test_json_mode_agent_returns_response_format_for_openrouter(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::OpenRouter);

        $this->assertSame(['response_format' => ['type' => 'json_object']], $options);
    }

    public function test_json_mode_agent_returns_response_format_for_openai_compatible(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::OpenAICompatible);

        $this->assertSame(['response_format' => ['type' => 'json_object']], $options);
    }

    // Anthropic — немає підтримки json_object mode

    public function test_json_mode_agent_returns_empty_for_anthropic(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $options = $agent->providerOptions(Lab::Anthropic);

        $this->assertSame([], $options);
    }

    // Невідомий провайдер

    public function test_json_mode_agent_returns_empty_for_unknown_provider(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $this->assertSame([], $agent->providerOptions('unknown'));
    }

    // String provider variants

    public function test_json_mode_agent_accepts_string_provider(): void
    {
        $agent = new JsonModeAgent('', [], []);

        $this->assertSame(['text' => ['format' => ['type' => 'json_object']]], $agent->providerOptions('openai'));
        $this->assertSame(['text' => ['format' => ['type' => 'json_object']]], $agent->providerOptions('xai'));
        $this->assertSame(['response_mime_type' => 'application/json'], $agent->providerOptions('gemini'));
        $this->assertSame(['response_format' => ['type' => 'json_object']], $agent->providerOptions('deepseek'));
        $this->assertSame(['response_format' => ['type' => 'json_object']], $agent->providerOptions('openai-compatible'));
        $this->assertSame([], $agent->providerOptions('anthropic'));
        $this->assertSame([], $agent->providerOptions('unknown'));
    }
}
