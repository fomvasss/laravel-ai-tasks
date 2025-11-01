<?php

namespace Fomvasss\AiTasks\DTO;

final class AiPayload
{
    public function __construct(
        public string $modality,
        public array  $messages = [],
        public array  $options  = [],
        public array  $meta     = [],
    ) {}
}
