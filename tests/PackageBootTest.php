<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Orchestra\Testbench\TestCase;

class PackageBootTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    public function test_config_loaded(): void
    {
        $this->assertNotEmpty(config('ai-tasks.default'));
    }

    public function test_queues_config_present(): void
    {
        $this->assertNotNull(config('ai-tasks.queues.default'));
        $this->assertNotNull(config('ai-tasks.queues.post'));
    }
}
