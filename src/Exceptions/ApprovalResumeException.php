<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Exceptions;

use RuntimeException;

/**
 * AI::resume() refused: nothing ran and the run is left as it was, except after
 * alreadyResolved() — another resume claimed it first.
 */
class ApprovalResumeException extends RuntimeException
{
    public static function notPaused(string $runId, string $status): self
    {
        return new self("Run {$runId} is not waiting for tool approval (status: {$status}).");
    }

    public static function alreadyResolved(string $runId): self
    {
        return new self("Run {$runId} was already resumed.");
    }

    public static function expired(string $runId): self
    {
        return new self("Run {$runId} waited for tool approval too long and expired.");
    }

    public static function otherTask(string $runId, string $expected, string $given): self
    {
        return new self("Run {$runId} was paused by {$expected}, not {$given}.");
    }

    /** @param list<string> $tools */
    public static function toolsMissing(string $runId, array $tools): self
    {
        return new self("Run {$runId} cannot resume: the task no longer provides " . implode(', ', $tools) . '.');
    }
}
