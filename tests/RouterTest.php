<?php

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\Tasks\AiTask;
use Orchestra\Testbench\TestCase;

class RouterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [\Fomvasss\AiTasks\AiServiceProvider::class];
    }

    public function test_router_returns_fallback()
    {
        $router = new Router();
        $task = new class extends AiTask {
            public function name(): string { return 'unknown.task'; }
            public function modality(): string { return 'text'; }
            public function toPayload(): \Fomvasss\AiTasks\DTO\AiPayload { return new \Fomvasss\AiTasks\DTO\AiPayload('text'); }
        };
        $this->assertNotEmpty($router->choose($task));
    }
}
