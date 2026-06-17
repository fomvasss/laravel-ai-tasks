<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;

final class AiTaskQueued
{
    public function __construct(
        public readonly AiTask $task,
        public readonly AiRun  $run,
    ) {}
}
