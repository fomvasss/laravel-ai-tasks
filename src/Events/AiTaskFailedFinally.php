<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;

/**
 * The task ended without a result — fired once, alongside AiTask::onFailed(). Unlike
 * AiTaskFailed (one driver's attempt failed, the chain may still succeed) this is final.
 * $run is null when nothing was started (the budget was already exceeded before any call).
 */
final class AiTaskFailedFinally
{
    public function __construct(
        public readonly AiTask            $task,
        public readonly \Throwable|string $reason,
        public readonly ?AiRun            $run = null,
    ) {}
}
