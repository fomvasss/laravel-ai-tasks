<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class CustomTableNameTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-tasks.table', 'custom_ai_runs');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
    }

    public function test_migration_creates_configured_table(): void
    {
        $this->assertTrue(Schema::hasTable('custom_ai_runs'));
        $this->assertFalse(Schema::hasTable('ai_runs'));
    }

    public function test_model_uses_configured_table(): void
    {
        $this->assertEquals('custom_ai_runs', (new AiRun())->getTable());
    }

    public function test_model_writes_to_configured_table(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task'      => 'demo',
            'driver'    => 'openai',
            'modality'  => 'text',
            'status'    => 'running',
            'request'   => ['modality' => 'text'],
        ]);

        $this->assertDatabaseHas('custom_ai_runs', [
            'id'        => $run->id,
            'tenant_id' => 't1',
            'status'    => 'running',
        ]);
    }
}
