# AiTask methods

`Fomvasss\AiTasks\Tasks\AiTask`. Only `modality()` and `toPayload()` are required.

## Request

| Method | Default | Description |
|---|---|---|
| `modality(): string` | — | `text`, `image`, `embed`, `audio` or `transcription` |
| `toPayload(): AiPayload` | — | Builds the request, see [AiPayload](payload-response.md#aipayload) |
| `name(): string` | class name without `Task`, snake_case | Used for routing, dashboard, `ai_runs.task`, fake responses |
| `setName(string $name): static` | — | Sets the name on an instance |
| `tools(): array` | `[]` | `Laravel\Ai\Contracts\Tool[]`, see [Tools & MCP](../usage/tools.md) |
| `toolChoice(): ToolChoice\|string\|array\|null` | `null` | Force a tool call, see [Tool choice](../usage/tool-approval.md) |
| `maxSteps(): ?int` | `null` | Step budget of the tool loop; `null` — 1.5× the number of tools, at most 25. See [Step budget](../guides/tools-in-practice.md#step-budget) |
| `approvalTtlMinutes(): ?int` | `approvals.ttl_minutes` | How long a pause for tool approval stays resumable; `null` — no limit. See [Resuming](../usage/tool-approval.md#resuming) |
| `schema(): ?Closure` | `null` | JSON Schema for structured output, see [Structured output](../usage/structured-output.md) |
| `viaDrivers(array\|string $drivers): static` | — | Driver chain for this instance, see [Routing](../usage/routing.md) |

## Result

| Method | Default | Description |
|---|---|---|
| `postprocess(AiResponse $response): AiResponse\|array` | returns `$response` | Shapes the response. Runs on every attempt — keep it free of side effects |
| `maxRetries(): int` | `0` | Retries when `isAcceptable()` rejects the result, queued path only |
| `isAcceptable(AiResponse\|array $result): bool` | `true` | Whether the postprocessed result is usable |
| `onCompleted(AiResponse\|array $result, bool $attemptsExhausted): void` | no-op | Once, for the final result |
| `onFailed(Throwable\|string $reason): void` | no-op | Once, when the task ends without a result |

See [Queued tasks](../usage/queued-tasks.md#retrying-an-unacceptable-result).

## Queue

| Method | Default | Description |
|---|---|---|
| `serializeForQueue(): array` | `[]` | Constructor arguments for rebuilding the task on the worker; also the idempotency source |
| `fromQueueArgs(array $args): static` | `new static(...$args)` | Rebuilds the task |
| `shouldRun(): bool` | `true` | Last check before the provider call; `false` marks the run `skipped` |
| `jobTimeout(): int` | `300` | Seconds before the worker kills the job |
| `idempotencyKey(): ?string` | hash of tenant, name, modality, args | `null` when `serializeForQueue()` is empty |
| `idempotencyWindow(): ?string` | `null` | Period string added to the key; `null` deduplicates forever |
| `viaQueues(): array` | `[]` | `['request' => ..., 'post' => ...]`, requires `ShouldQueueAi` |
| `onQueue(?string $queue): static` | — | Queue for both stages, requires `ShouldQueueAi` |
| `onConnection(?string $connection): static` | — | Queue connection, requires `ShouldQueueAi` |

| `executionContext(): array` | `[]` | Request-only state captured at dispatch and stored with the run (`request.execution_context`) |
| `static withExecutionContext(array $context, Closure $call): mixed` | runs `$call` | Applies the context around the provider call, the worker hooks and a retry; restores in `finally` |

`use ActsAsDispatchingUser;` implements both for the user and the locale, see [Acting as a user](../guides/tools-in-practice.md#acting-as-a-user).

`use SerializesModelsAi;` implements `serializeForQueue()`/`fromQueueArgs()` for promoted constructor properties, including Eloquent models. See [Queued tasks](../usage/queued-tasks.md#serializing-the-task).

## Tenant and subject (protected)

| Method | Default | Description |
|---|---|---|
| `tenantId(): ?string` | `null` → `TenantResolver` | Tenant the run is billed to |
| `userId(): ?string` | `null` → `auth()->id()` at dispatch | Who started the run (`ai_runs.user_id`) |
| `subjectType(): ?string` | `null` | Type of the record the run concerns, e.g. `order` |
| `subjectId(): ?string` | `null` | Its id |
| `defaultMeta(): array` | `[]` | Goes to `context()->meta` — visible in the `AiTaskStarted` event, not stored in `ai_runs` (use `AiPayload::$meta` for that) |

`context(): AiContext` returns the resolved tenant, user, name, subject and meta; it's computed once per instance.

See [Budgets & tenants](../usage/budgets.md).
