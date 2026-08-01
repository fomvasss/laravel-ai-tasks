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

    private ?AiContext $cachedContext = null;

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
        return $this->cachedContext ??= new AiContext(
            tenantId: app(TenantResolver::class)->id(),
            taskName: $this->name(),
            subjectType: null,
            subjectId: null,
            meta: $this->defaultMeta(),
        );
    }

    public function tools(): array
    {
        return [];
    }

    /**
     * Declare a JSON Schema for structured, provider-native output.
     * When set, the model's response is validated/shaped against this schema
     * instead of relying on jsonMode + prompt instructions.
     *
     * @return (\Closure(\Illuminate\Contracts\JsonSchema\JsonSchema): array<string, \Illuminate\JsonSchema\Types\Type>)|null
     */
    public function schema(): ?\Closure
    {
        return null;
    }

    /**
     * Force whether and which tool the model must call.
     * Accepts a \Laravel\Ai\ToolChoice instance, a mode string ('auto'|'none'|'required'),
     * or a tool name (forces that specific tool).
     */
    public function toolChoice(): \Laravel\Ai\ToolChoice|string|array|null
    {
        return null;
    }

    public function jobTimeout(): int
    {
        return 300;
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function postprocess(AiResponse $response): AiResponse|array
    {
        return $response;
    }

    /**
     * Optional hook called exactly once, at the same point AiTaskCompleted fires — after
     * postprocess()/isAcceptable() have settled on a final result (accepted, or retries
     * exhausted). Unlike postprocess(), this does not run on rejected intermediate attempts.
     * Prefer this over an AiTaskCompleted listener for a single-consumer task; keep a listener
     * when several independent consumers need to react to the same task without editing it.
     */
    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        // no-op — override is optional
    }

    /**
     * Maximum number of automatic retries when isAcceptable() rejects the postprocessed result —
     * a response the provider returned "successfully" (ok=true, no exception) but that is still
     * unusable (e.g. blank/whitespace content from a reasoning model that spent its whole token
     * budget on internal reasoning). Only applies to the queued path (AI::queue()); AI::send()/
     * stream() do not retry. Retries reuse the original run's driver and dispatch fresh
     * ProcessAiPayload/PostprocessAiResult jobs — the task itself never sees or tracks the attempt
     * number, and serializeForQueue()/idempotencyKey() need no changes to support this.
     */
    public function maxRetries(): int
    {
        return 0;
    }

    /**
     * Whether the postprocessed result is usable. Return false for a result that came back
     * "successfully" from the provider but is still semantically unusable — see maxRetries().
     * Only consulted when maxRetries() > 0.
     */
    public function isAcceptable(AiResponse|array $result): bool
    {
        return true;
    }

    public function idempotencyKey(): ?string
    {
        $args = $this->serializeForQueue();

        if (empty($args)) {
            return null;
        }

        return hash('xxh3', json_encode([
            $this->context()->tenantId,
            $this->name(),
            $this->modality(),
            $args,
        ]));
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
