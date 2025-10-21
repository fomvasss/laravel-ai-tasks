<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

abstract class AiTask
{
    abstract public function name(): string;
    abstract public function modality(): string;
    abstract public function toPayload(): AiPayload;

    public function context(): AiContext
    {
        $tenantId = app(\Fomvasss\AiTasks\Support\TenantResolver::class)->id();

        return new \Fomvasss\AiTasks\DTO\AiContext(
            tenantId: $tenantId,
            taskName: $this->name(),
            subjectType: null,
            subjectId: null,
            meta: ['locale' => app()->getLocale()] // TODO
        );
    }

    public function postprocess(AiResponse $resp): AiResponse|array
    {
        return $resp;
    }

    public function idempotencyKey(): string
    {
        return hash('xxh3', json_encode([$this->name(), $this->modality(), $this->toPayload(), $this->context()->meta]));
    }

    public static function fromQueueArgs(array $args): AiTask
    {
        return new static(...$args);
    }
    
    public function serializeForQueue(): array { return []; }
}
