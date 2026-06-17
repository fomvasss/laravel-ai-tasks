<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;

final class AiTaskCompleted
{
    public function __construct(
        public readonly AiTask    $task,
        public readonly AiResponse $response,
        public readonly AiRun     $run,
    ) {}
}
