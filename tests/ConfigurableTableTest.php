<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ConfigurableTableTest extends TestCase
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

    public function test_default_table_name_is_ai_runs(): void
    {
        $this->assertTrue(Schema::hasTable('ai_runs'));
        $this->assertEquals('ai_runs', (new AiRun())->getTable());
    }
}
