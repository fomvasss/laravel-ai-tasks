<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Tasks\AiTask;

class Router
{
    public function choose(AiTask $task): array
    {
        if ($custom = $task->preferredDrivers()) {
            return $custom;
        }

        if ($byTask = config("ai.routing.{$task->name()}")) {
            return (array) $byTask;
        }

        return [app(AiManager::class)->getDefaultDriver()];
    }

    public function first(AiTask $task): string
    {
        return $this->choose($task)[0];
    }
}
