<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Support\Cost;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Orchestra\Testbench\TestCase;

class TranscriptionCostTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Laravel\Ai\AiServiceProvider::class, AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'sk-test']);
    }

    private function transcribe(TranscriptionUsage $usage, array $cfg): array
    {
        Transcription::fake([
            new TranscriptionResponse('hello', new Collection, $usage, new Meta('openai', 'whisper-1')),
        ]);

        $resp = (new LaravelAiDriver('openai', $cfg))->send(
            new AiPayload(modality: 'transcription', options: ['path' => __FILE__]),
            new AiContext(tenantId: 't', taskName: 'transcribe'),
        );

        return $resp->usage;
    }

    public function test_calc_by_seconds(): void
    {
        $this->assertEqualsWithDelta(0.009, Cost::calcBySeconds(90, ['price' => ['per_minute' => 0.006]]), 1e-9);
        $this->assertNull(Cost::calcBySeconds(90, ['price' => ['in' => 1.0]]));
    }

    public function test_duration_billed_model_is_costed_per_minute(): void
    {
        $usage = $this->transcribe(
            new TranscriptionUsage(audioSeconds: 120.0),
            ['prices' => ['whisper-1' => ['per_minute' => 0.006]]],
        );

        $this->assertSame('whisper-1', $usage['model'], 'модель береться з відповіді, коли в payload її немає');
        $this->assertSame(120.0, $usage['audio_seconds']);
        $this->assertEqualsWithDelta(0.012, $usage['cost'], 1e-9);
        $this->assertSame(0.006, $usage['cost_rates']['per_minute']);
    }

    public function test_token_billed_model_falls_back_to_token_cost(): void
    {
        $usage = $this->transcribe(
            new TranscriptionUsage(1_000_000, 1_000_000, audioSeconds: 60.0),
            ['price' => ['in' => 2.5, 'out' => 10.0]],
        );

        $this->assertSame(60.0, $usage['audio_seconds']);
        $this->assertEqualsWithDelta(12.5, $usage['cost'], 1e-9);
    }

    public function test_unknown_duration_keeps_token_cost(): void
    {
        $usage = $this->transcribe(
            new TranscriptionUsage(1_000_000, 0),
            ['price' => ['in' => 2.5, 'per_minute' => 0.006]],
        );

        $this->assertArrayNotHasKey('audio_seconds', $usage);
        $this->assertEqualsWithDelta(2.5, $usage['cost'], 1e-9);
    }
}
