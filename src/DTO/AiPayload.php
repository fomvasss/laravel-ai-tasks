<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

final class AiPayload
{
    public function __construct(
        public readonly string  $modality,
        public readonly array   $messages = [],
        public readonly ?string $systemPrompt = null,
        public readonly array   $options = [],
        public readonly array   $meta = [],
        public readonly array   $tools = [],
        public readonly bool    $jsonMode = false,
    ) {}
}
