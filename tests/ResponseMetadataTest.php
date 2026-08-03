<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Orchestra\Testbench\TestCase;

class ResponseMetadataTest extends TestCase
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

    // ── Unit: AiResponse DTO defaults ─────────────────────────────────────

    public function test_tool_calls_defaults_to_empty(): void
    {
        $response = new AiResponse(ok: true, content: 'hi');

        $this->assertSame([], $response->toolCalls);
    }

    public function test_finish_reason_defaults_to_null(): void
    {
        $response = new AiResponse(ok: true, content: 'hi');

        $this->assertNull($response->finishReason);
    }

    public function test_tool_calls_and_finish_reason_can_be_set(): void
    {
        $response = new AiResponse(ok: true, content: 'hi', toolCalls: [['name' => 'search']], finishReason: 'length');

        $this->assertSame([['name' => 'search']], $response->toolCalls);
        $this->assertSame('length', $response->finishReason);
    }

    // ── Unit: LaravelAiDriver::buildTextResponse() mapping ────────────────

    private function callBuildTextResponse(AgentResponse $response, string $provider = 'openai', ?string $model = 'gpt-4o-mini'): AiResponse
    {
        $driver = new LaravelAiDriver($provider, ['model' => $model]);
        $method = new \ReflectionMethod($driver, 'buildTextResponse');
        $method->setAccessible(true);

        return $method->invoke($driver, $response, $provider, $model);
    }

    public function test_build_text_response_maps_finish_reason_from_last_step(): void
    {
        $usage = new Usage(10, 5);
        $meta  = new Meta('openai', 'gpt-4o-mini');

        $steps = new Collection([
            new Step('partial', [], [], FinishReason::ToolCalls, $usage, $meta),
            new Step('final', [], [], FinishReason::Length, $usage, $meta),
        ]);

        $response = (new AgentResponse('inv-1', 'final', $usage, $meta))->withSteps($steps);

        $result = $this->callBuildTextResponse($response);

        $this->assertSame('length', $result->finishReason);
    }

    public function test_build_text_response_defaults_finish_reason_to_null_without_steps(): void
    {
        $usage    = new Usage(10, 5);
        $meta     = new Meta('openai', 'gpt-4o-mini');
        $response = new AgentResponse('inv-1', 'final', $usage, $meta);

        $result = $this->callBuildTextResponse($response);

        $this->assertNull($result->finishReason);
    }

    public function test_build_text_response_maps_tool_calls(): void
    {
        $usage    = new Usage(10, 5);
        $meta     = new Meta('openai', 'gpt-4o-mini');
        $toolCall = new ToolCall('call-1', 'search', ['q' => 'laravel']);

        $response = (new AgentResponse('inv-1', 'final', $usage, $meta))
            ->withToolCallsAndResults(new Collection([$toolCall]), new Collection);

        $result = $this->callBuildTextResponse($response);

        $this->assertSame([$toolCall->toArray()], $result->toolCalls);
    }

    public function test_build_text_response_defaults_tool_calls_to_empty(): void
    {
        $usage    = new Usage(10, 5);
        $meta     = new Meta('openai', 'gpt-4o-mini');
        $response = new AgentResponse('inv-1', 'final', $usage, $meta);

        $result = $this->callBuildTextResponse($response);

        $this->assertSame([], $result->toolCalls);
    }

    public function test_build_text_response_reads_structured_only_from_structured_agent_response(): void
    {
        $usage = new Usage(10, 5);
        $meta  = new Meta('openai', 'gpt-4o-mini');

        $plain = new AgentResponse('inv-1', 'final', $usage, $meta);
        $this->assertNull($this->callBuildTextResponse($plain)->structured);

        $structured = new StructuredAgentResponse('inv-2', ['a' => 1], '{"a":1}', $usage, $meta);
        $this->assertSame(['a' => 1], $this->callBuildTextResponse($structured)->structured);
    }

    // ── Unit: AiRun::finish() persists tool_calls / finish_reason ─────────

    public function test_finish_persists_tool_calls_and_finish_reason(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task' => 'demo',
            'driver' => 'openai',
            'modality' => 'text',
            'status' => 'running',
            'request' => ['modality' => 'text'],
            'started_at' => now(),
        ]);

        $run->finish(new AiResponse(
            ok: true,
            content: 'hi',
            toolCalls: [['id' => 'call-1', 'name' => 'search']],
            finishReason: 'stop',
        ));
        $run->refresh();

        $this->assertSame([['id' => 'call-1', 'name' => 'search']], $run->response['tool_calls'] ?? null);
        $this->assertSame('stop', $run->response['finish_reason'] ?? null);
    }

    public function test_finish_omits_tool_calls_and_finish_reason_when_absent(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task' => 'demo',
            'driver' => 'openai',
            'modality' => 'text',
            'status' => 'running',
            'request' => ['modality' => 'text'],
            'started_at' => now(),
        ]);

        $run->finish(new AiResponse(ok: true, content: 'hi'));
        $run->refresh();

        $this->assertArrayNotHasKey('tool_calls', $run->response ?? []);
        $this->assertArrayNotHasKey('finish_reason', $run->response ?? []);
    }

    // ── Integration: queued round-trip — toolCalls/finishReason survive ──

    public function test_queued_postprocess_receives_tool_calls_and_finish_reason(): void
    {
        $task = new class extends AiTask {
            public static ?array $seen = null;
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
            public function postprocess(AiResponse $resp): array
            {
                return ['tool_calls' => $resp->toolCalls, 'finish_reason' => $resp->finishReason];
            }
            public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
            {
                static::$seen = $result;
            }
        };
        $task::$seen = null;

        $run = AiRun::startAsQueue('driverA', $task->toPayload(), $task->context(), $task);
        $run->markRunning();
        $run->finish(new AiResponse(
            ok: true,
            content: 'hi',
            toolCalls: [['id' => 'call-1', 'name' => 'search']],
            finishReason: 'tool_calls',
        ));

        (new PostprocessAiResult($run->id, $task::class, $task->serializeForQueue(), attempt: 0))->handle();

        $this->assertSame([
            'tool_calls' => [['id' => 'call-1', 'name' => 'search']],
            'finish_reason' => 'tool_calls',
        ], $task::$seen);
    }
}
