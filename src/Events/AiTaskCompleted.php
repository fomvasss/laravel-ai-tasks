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
        // true when isAcceptable() rejected this result AND maxRetries() attempts were already used up —
        // lets listeners tell "final, exhausted failure" apart from a normal accepted result without
        // re-deriving the task's own isAcceptable() check.
        public readonly bool      $attemptsExhausted = false,
    ) {}
}
