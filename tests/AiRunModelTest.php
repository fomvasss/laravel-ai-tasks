<?php

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;

class AiRunModelTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [\Fomvasss\AiTasks\AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // sqlite :memory:
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->artisan('migrate')->run();
    }

    public function test_create_ai_run()
    {
        $run = AiRun::create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'tenant_id' => 't1',
            'task' => 'demo',
            'driver' => 'openai',
            'modality' => 'text',
            'status' => 'running',
            'request' => ['x' => 1],
        ]);

        $this->assertEquals('t1', $run->tenant_id);
        $this->assertEquals('running', $run->status);
    }
}
