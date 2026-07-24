<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

final class AiResponse
{
    public function __construct(
        public readonly bool    $ok,
        public readonly ?string $content   = null,
        public readonly array   $usage     = [],
        public readonly array   $raw       = [],
        public readonly ?string $error     = null,
        public readonly array   $toolCalls = [],
        public readonly ?array  $structured = null,
    ) {}
}
