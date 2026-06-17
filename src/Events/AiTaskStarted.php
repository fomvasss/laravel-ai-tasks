<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;

final class AiTaskStarted
{
    public function __construct(
        public readonly AiTask    $task,
        public readonly AiContext $context,
        public readonly AiRun     $run,
    ) {}
}
