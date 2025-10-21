<?php

namespace Fomvasss\AiTasks\DTO;

final class WebhookPayload
{
    public function __construct(
        public string $providerRunId,    // run_id/job_id провайдера
        public string $status,           // 'succeeded'|'failed'|'canceled'
        public mixed  $content = null,   // text/array/URL/… (result)
        public array  $usage = [],       // tokens/cost/meta
        public ?string $error = null     // error message
    ) {}
}