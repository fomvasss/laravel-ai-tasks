<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Orchestra\Testbench\TestCase;

class AiRunModelTest extends TestCase
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

    public function test_create_ai_run(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task'      => 'demo',
            'driver'    => 'openai',
            'modality'  => 'text',
            'status'    => 'running',
            'request'   => ['modality' => 'text'],
        ]);

        $this->assertEquals('t1', $run->tenant_id);
        $this->assertEquals('running', $run->status);
        $this->assertNotNull($run->id);
    }

    public function test_finish_saves_token_columns(): void
    {
        $run = AiRun::create([
            'tenant_id'  => 't1',
            'task'       => 'demo',
            'driver'     => 'openai',
            'modality'   => 'text',
            'status'     => 'running',
            'request'    => ['modality' => 'text'],
            'started_at' => now(),
        ]);

        $response = new AiResponse(
            ok: true,
            content: 'hello',
            usage: [
                'tokens_in'          => 100,
                'tokens_out'         => 50,
                'cache_read_tokens'  => 10,
                'cache_write_tokens' => 5,
                'cost'               => 0.00025,
            ],
        );

        $run->finish($response);
        $run->refresh();

        $this->assertEquals(100, $run->tokens_in);
        $this->assertEquals(50, $run->tokens_out);
        $this->assertEquals(10, $run->cache_read_tokens);
        $this->assertEquals(5, $run->cache_write_tokens);
        $this->assertEquals(0.00025, $run->cost);
        $this->assertEquals('ok', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_cost_is_nullable_when_not_provided(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't2',
            'task'      => 'demo',
            'driver'    => 'ollama',
            'modality'  => 'text',
            'status'    => 'running',
            'request'   => ['modality' => 'text'],
            'started_at'=> now(),
        ]);

        $response = new AiResponse(ok: true, content: 'hi', usage: [
            'tokens_in'  => 20,
            'tokens_out' => 10,
        ]);

        $run->finish($response);
        $run->refresh();

        $this->assertNull($run->cost);
        $this->assertEquals(20, $run->tokens_in);
    }
}
