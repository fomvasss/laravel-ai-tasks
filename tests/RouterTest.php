<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Orchestra\Testbench\TestCase;

class RouterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    private function makeTask(): AiTask
    {
        return new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };
    }

    public function test_router_returns_default_when_no_routing_config(): void
    {
        $router = new Router();
        $result = $router->choose($this->makeTask());

        $this->assertNotEmpty($result);
        $this->assertIsArray($result);
    }

    public function test_router_returns_configured_drivers(): void
    {
        config(['ai.routing.summarize' => ['openai', 'anthropic']]);

        $router = new Router();
        $task   = $this->makeTask()->setName('summarize');

        $this->assertSame(['openai', 'anthropic'], $router->choose($task));
    }

    public function test_router_first_returns_string(): void
    {
        $router = new Router();
        $result = $router->first($this->makeTask());

        $this->assertIsString($result);
    }

    public function test_task_name_derived_from_class(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text'); }
        };

        $this->assertIsString($task->name());
        $this->assertNotEmpty($task->name());
    }
}
