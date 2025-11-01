<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Traits\QueueableAi;
use Fomvasss\AiTasks\Traits\RoutesDrivers;

abstract class AiTask
{
    use QueueableAi,
        RoutesDrivers;

    protected ?string $customName = null;

    abstract public function modality(): string;

    abstract public function toPayload(): AiPayload;

    public function setName(string $name): static
    {
        $this->customName = $name;

        return $this;
    }

    public function name(): string
    {
        if ($this->customName) {
            return $this->customName;
        }
        $base = class_basename(static::class);
        $base = preg_replace('/Task$/', '', $base) ?: $base;

        $parts = preg_split('/(?=[A-Z])/', $base, -1, PREG_SPLIT_NO_EMPTY);

        return strtolower(implode('_', $parts));
    }

    public function context(): AiContext
    {
        $tenantId = app(\Fomvasss\AiTasks\Support\TenantResolver::class)->id();

        return new \Fomvasss\AiTasks\DTO\AiContext(
            tenantId: $tenantId,
            taskName: $this->name(),
            subjectType: null,
            subjectId: null,
            meta: $this->defaultMeta(),
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

    public function serializeForQueue(): array
    {
        return [];
    }

    protected function defaultMeta(): array
    {
        return [];
    }

    public function preferredModels(): array {
        return [];
    }
}
