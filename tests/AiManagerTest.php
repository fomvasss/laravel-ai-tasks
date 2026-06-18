<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Drivers\NullDriver;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Orchestra\Testbench\TestCase;

class AiManagerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    public function test_null_driver_returns_null_driver_instance(): void
    {
        $manager = app(AiManager::class);
        $driver  = $manager->driver('null');

        $this->assertInstanceOf(NullDriver::class, $driver);
    }

    public function test_configured_driver_returns_laravel_ai_driver(): void
    {
        config(['ai-tasks.drivers.openai' => ['model' => 'gpt-4o']]);

        $manager = app(AiManager::class);
        $driver  = $manager->driver('openai');

        $this->assertInstanceOf(LaravelAiDriver::class, $driver);
    }

    public function test_unknown_driver_throws_exception(): void
    {
        $this->expectException(AiDriverException::class);

        app(AiManager::class)->driver('nonexistent-provider');
    }
}
