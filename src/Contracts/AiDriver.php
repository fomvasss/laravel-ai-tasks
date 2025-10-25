<?php

namespace Fomvasss\AiTasks\Contracts;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

interface AiDriver
{
    public function supports(string $modality): bool;

    public function send(AiPayload $payload, AiContext $ctx): AiResponse;

    public function stream(AiPayload $payload, AiContext $ctx, callable $onChunk): AiResponse;

    //public function queue(AiPayload $payload, AiContext $ctx, ?string $queue = null): string;
}
