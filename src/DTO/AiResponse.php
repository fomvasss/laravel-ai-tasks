<?php

namespace Fomvasss\AiTasks\DTO;

final class AiResponse
{
    public function __construct(
        public bool    $ok,
        public ?string $content = null,
        public array   $usage = [],
        public array   $raw = [],
        public ?string $error = null
    ) {}
}
