<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Tasks\AiTask;

/**
 * Applies a task class's execution context (AiTask::executionContext()) around a call, by class
 * name — the queued path has only the class until the task is rebuilt, and rebuilding it may
 * already need the context. A class that is gone or not a task runs the call as is, so the
 * caller's own error handling reports it.
 */
final class ExecutionContext
{
    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    public static function run(string $taskClass, array $context, \Closure $call): mixed
    {
        if ($context === [] || ! class_exists($taskClass) || ! is_subclass_of($taskClass, AiTask::class)) {
            return $call();
        }

        return $taskClass::withExecutionContext($context, $call);
    }
}
