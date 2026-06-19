<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\TenantResolver;
use Fomvasss\AiTasks\Traits\QueueableAi;
use Fomvasss\AiTasks\Traits\RoutesDrivers;

abstract class AiTask
{
    use QueueableAi, RoutesDrivers;

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

        $base  = preg_replace('/Task$/', '', class_basename(static::class)) ?: class_basename(static::class);
        $parts = preg_split('/(?=[A-Z])/', $base, -1, PREG_SPLIT_NO_EMPTY);

        return strtolower(implode('_', $parts));
    }

    public function context(): AiContext
    {
        return new AiContext(
            tenantId:    app(TenantResolver::class)->id(),
            taskName:    $this->name(),
            subjectType: null,
            subjectId:   null,
            meta:        $this->defaultMeta(),
        );
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function postprocess(AiResponse $response): AiResponse|array
    {
        return $response;
    }

    public function idempotencyKey(): string
    {
        return hash('xxh3', json_encode([$this->name(), $this->modality(), $this->context()->meta]));
    }

    public function serializeForQueue(): array
    {
        return [];
    }

    public static function fromQueueArgs(array $args): static
    {
        return new static(...$args);
    }

    protected function defaultMeta(): array
    {
        return [];
    }
}
