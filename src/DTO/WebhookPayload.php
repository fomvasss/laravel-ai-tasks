<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

final class WebhookPayload
{
    public function __construct(
        public readonly string  $providerRunId,
        public readonly string  $status,
        public readonly mixed   $content = null,
        public readonly array   $usage   = [],
        public readonly ?string $error   = null,
    ) {}
}
