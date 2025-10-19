<?php

namespace Fomvasss\AiTasks\DTO;

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
