<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Exceptions\ModelListingException;
use Fomvasss\AiTasks\Exceptions\ModelListingUnavailableException;
use Fomvasss\AiTasks\Support\ModelLister;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ModelListerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    public function test_openai_models_are_sorted_and_filtered(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o-mini', 'owned_by' => 'openai', 'created' => 1700000000],
                    ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai', 'created' => 1750000000],
                    ['id' => 'text-embedding-3-small', 'owned_by' => 'openai', 'created' => 1690000000],
                ],
            ]),
        ]);

        $models = (new ModelLister())->forDriver('openai', ['api_key' => 'sk-test'], filter: 'gpt');

        $this->assertCount(2, $models);
        $this->assertSame('gpt-4o-mini', $models[0]['id']);
        $this->assertSame('gpt-5.6-luna', $models[1]['id']);
        $this->assertSame('2023-11-14', $models[0]['created']);
    }

    public function test_openai_api_error_throws_model_listing_exception(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['error' => ['message' => 'invalid api key']], 401),
        ]);

        $this->expectException(ModelListingException::class);
        $this->expectExceptionMessage('invalid api key');

        (new ModelLister())->forDriver('openai', ['api_key' => 'bad-key']);
    }

    public function test_gemini_strips_models_prefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'models' => [
                    [
                        'name'                       => 'models/gemini-3.6-flash',
                        'inputTokenLimit'            => 1000000,
                        'outputTokenLimit'            => 64000,
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                ],
            ]),
        ]);

        $models = (new ModelLister())->forDriver('gemini', ['api_key' => 'test']);

        $this->assertSame('gemini-3.6-flash', $models[0]['id']);
        $this->assertSame(1000000, $models[0]['context_in']);
        $this->assertSame('generateContent', $models[0]['methods']);
    }

    public function test_anthropic_maps_capabilities(): void
    {
        Http::fake([
            'api.anthropic.com/v1/models' => Http::response([
                'data' => [
                    [
                        'id'                => 'claude-sonnet-5',
                        'display_name'      => 'Claude Sonnet 5',
                        'created_at'        => '2026-06-30T00:00:00Z',
                        'max_input_tokens'  => 1000000,
                        'max_tokens'        => 64000,
                        'capabilities'      => ['thinking' => ['supported' => true]],
                    ],
                ],
            ]),
        ]);

        $models = (new ModelLister())->forDriver('anthropic', ['api_key' => 'test']);

        $this->assertSame('Claude Sonnet 5', $models[0]['display_name']);
        $this->assertSame('2026-06-30', $models[0]['created']);
        $this->assertSame('thinking', $models[0]['capabilities']);
    }

    public function test_openai_compatible_driver_uses_default_listing_url(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'openai/gpt-oss-120b', 'owned_by' => 'groq', 'created' => 1750000000],
                ],
            ]),
        ]);

        $models = (new ModelLister())->forDriver('groq', ['api_key' => 'test']);

        $this->assertSame('openai/gpt-oss-120b', $models[0]['id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/models');
    }

    public function test_unknown_driver_without_url_is_unavailable(): void
    {
        $this->expectException(ModelListingUnavailableException::class);

        (new ModelLister())->forDriver('some-custom-driver', ['api_key' => 'test']);
    }
}
