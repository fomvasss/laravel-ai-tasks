<?php

namespace Fomvasss\AiTasks\DTO;

/**
 * Context information about the AI task.
 * A call "passport" so that the system knows who/what/over what/in what environment is performing AI tasks. 
 * Without it, there can be no clean auditing, budgeting, or stable routing.
 * 
 */
final class AiContext
{
    public function __construct(
        public string  $tenantId,
        public string  $taskName,
        public ?string $subjectType = null,
        public ?string $subjectId   = null,
        public array   $meta        = []
    ) {}
}
