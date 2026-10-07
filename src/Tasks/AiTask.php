<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\TenantResolver;
use Fomvasss\AiTasks\Traits\QueueableAi;
use Fomvasss\AiTasks\Traits\RoutesDrivers;
use Laravel\Ai\Approvals\Decisions;

abstract class AiTask
{
    use QueueableAi, RoutesDrivers;

    protected ?string $customName = null;

    private ?AiContext $cachedContext = null;

    private ?AiRun $resumingRun = null;

    private ?Decisions $resumeDecisions = null;

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
            tenantId: $this->tenantId() ?? app(TenantResolver::class)->id(),
            taskName: $this->name(),
            subjectType: $this->subjectType(),
            subjectId: $this->subjectId(),
            meta: $this->defaultMeta(),
            userId: $this->userId() ?? self::authenticatedUserId(),
        );
    }

    /**
     * Override to record who started this run (ai_runs.user_id) when it is not the authenticated
     * user — e.g. a task dispatched from a job on someone's behalf. Return null (default) to fall
     * back to auth()->id() at dispatch; a run started with nobody logged in records null.
     * Purely for audit and filtering — unrelated to tenantId() (who's billed).
     */
    protected function userId(): ?string
    {
        return null;
    }

    private static function authenticatedUserId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Override to pin this task's tenant explicitly (e.g. from a model the task already holds —
     * an organization/account id) instead of relying on the app-wide TenantResolver, which only
     * has access to the current request/auth state and nothing task-specific. Return null (default)
     * to fall back to TenantResolver — useful when only some tasks need an explicit tenant.
     */
    protected function tenantId(): ?string
    {
        return null;
    }

    /**
     * Override together with subjectId() to tag this run with the business record it concerns
     * (e.g. 'chat', 'order') — lets you query AiRun by subject instead of only by tenant/task.
     * Purely for filtering/audit; unrelated to tenantId() (who's billed) or to Laravel's morph maps.
     */
    protected function subjectType(): ?string
    {
        return null;
    }

    protected function subjectId(): ?string
    {
        return null;
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

    /**
     * Step budget of the tool loop. null — laravel/ai's default: 1.5× the number of tools
     * (at most 25), so a task with two or three tools is cut off after 3–4 steps. An
     * explicit 'max_steps' in toPayload() options wins over this.
     */
    public function maxSteps(): ?int
    {
        return null;
    }

    /**
     * How long a pause for tool approval stays resumable, in minutes. null — no limit.
     * Defaults to approvals.ttl_minutes; override for a task whose answer comes later
     * (a scheduled digest proposing actions) or must come sooner.
     */
    public function approvalTtlMinutes(): ?int
    {
        $ttl = config('ai-tasks.approvals.ttl_minutes');

        return $ttl ? (int) $ttl : null;
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
     * Optional hook called exactly once when the task ends without a result: every driver of
     * the chain failed (for a queued task — on every retry), the provider rejected the request,
     * a streamed answer broke off midway, or the budget was exceeded. The counterpart of
     * onCompleted() — exactly one of the two is called for a task that was not skipped by
     * shouldRun(). Fires together with the AiTaskFailedFinally event.
     */
    public function onFailed(\Throwable|string $reason): void
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

        $parts = [
            $this->context()->tenantId,
            $this->name(),
            $this->modality(),
            $args,
        ];

        // appended only when set, so existing keys of tasks without a window stay stable
        if (($window = $this->idempotencyWindow()) !== null) {
            $parts[] = $window;
        }

        return hash('xxh3', json_encode($parts));
    }

    /**
     * Scope idempotency deduplication to a time window instead of forever. The returned
     * string becomes part of idempotencyKey(), so the same task+args may run again once
     * the window changes. Return e.g. now()->format('Y-m-d') to allow one run per day,
     * now()->format('Y-\WW') per week. Default null = deduplicate forever (a queued
     * task with identical args is never dispatched twice).
     */
    public function idempotencyWindow(): ?string
    {
        return null;
    }

    /**
     * The run this task is resuming (AI::resume()), or null on an ordinary run. toPayload()
     * must then return the history as it was when that run started — the conversation up to and
     * including the prompt that led to the pause, without what came after (the confirmation
     * text shown to the user, their "yes"): the package appends the paused turn and the
     * decisions to it.
     */
    public function resumingRun(): ?AiRun
    {
        return $this->resumingRun;
    }

    /** @internal set by AI::resume() */
    public function beginResume(AiRun $run, Decisions $decisions): static
    {
        $this->resumingRun     = $run;
        $this->resumeDecisions = $decisions;
        $this->cachedContext   = null;

        return $this;
    }

    /** @internal set by AI::resume() when it is done or refused */
    public function endResume(): void
    {
        $this->resumingRun     = null;
        $this->resumeDecisions = null;
        $this->cachedContext   = null;
    }

    /** @internal */
    public function resumeDecisions(): ?Decisions
    {
        return $this->resumeDecisions;
    }

    public function serializeForQueue(): array
    {
        return [];
    }

    /**
     * Request-only state the task's code needs wherever it runs — the acting user, the locale,
     * a header value. Captured when the task is dispatched (send()/queue(), in the caller's
     * process), stored with the run (ai_runs.request.execution_context) and handed to
     * withExecutionContext() wherever the package runs this task's code: the provider call with
     * its tool loop, postprocess()/onCompleted()/onFailed() on the worker, a dashboard Retry.
     * Scalars and arrays only — it is stored as JSON. Default [] — nothing is carried.
     * ActsAsDispatchingUser implements both methods for the user and the locale.
     *
     * @return array<string, mixed>
     */
    public function executionContext(): array
    {
        return [];
    }

    /**
     * Runs $call with the captured context applied and must restore everything it changed,
     * in `finally` — a queue worker runs many jobs in one process. Static because a queued task
     * is rebuilt from fromQueueArgs() inside the context: re-querying its models may already
     * depend on the user (global scopes).
     *
     * @template T
     * @param array<string, mixed> $context
     * @param \Closure(): T $call
     * @return T
     */
    public static function withExecutionContext(array $context, \Closure $call): mixed
    {
        return $call();
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
