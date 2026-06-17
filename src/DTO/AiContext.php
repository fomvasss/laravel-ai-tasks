<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

final class AiContext
{
    public function __construct(
        public readonly string  $tenantId,
        public readonly string  $taskName,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId   = null,
        public readonly array   $meta        = [],
    ) {}
}
