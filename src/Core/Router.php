<?php

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Tasks\AiTask;

class Router
{
    public function choose(AiTask $task): array
    {
        return config("ai.routing.{$task->name()}", [app(AiManager::class)->getDefaultDriver()]);
    }

    public function first(AiTask $task): string
    {
        return $this->choose($task)[0];
    }
}
