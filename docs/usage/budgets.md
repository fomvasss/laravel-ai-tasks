# Budgets & tenants

Every run belongs to a tenant (`ai_runs.tenant_id`). Budgets limit a tenant's monthly spend in USD.

```php
// config/ai-tasks.php
'budgets' => [
    'tenant-abc' => ['monthly_usd' => 50.0],
    'default' => ['monthly_usd' => 100.0],
],
```

A tenant without its own entry gets the `default` limit — each tenant separately, not a shared pool. Without a `default` entry such tenants are unlimited.

Current spend vs limit — [`ai:budget`](../reference/commands.md#aibudget), or from code, e.g. to show the remaining budget in your UI:

```php
use Fomvasss\AiTasks\Support\Budget;

$budget = app(Budget::class);

$budget->getMonthlyLimit($tenantId);      // ?float, null = unlimited
$budget->getMonthlySpent($tenantId);      // float, current month
$budget->getMonthlyRemaining($tenantId);  // ?float, null = unlimited
$budget->getSpentBetween($tenantId, $from, $to);
$budget->ensureNotExceeded($tenantId, expectedCost: 0.5); // throws BudgetExceededException
```

The month is the calendar month in the app's timezone, by `ai_runs.created_at`.

## How the tenant is resolved

1. `tenantId()` on the task, if it returns non-null
2. `TenantResolver`:
   1. the request header named in `tenant_header` — only when it's set (off by default)
   2. authenticated user's `tenant_id`, `company_id` or `id`
   3. `default_tenant` from config (`default`)

### Per task

When the task already knows its tenant (it holds a model with an `organization_id`), override `tenantId()`:

```php
protected function tenantId(): ?string
{
    return $this->order->organization_id; // null falls back to TenantResolver
}
```

### Custom resolver

Bind your own resolver in a service provider:

```php
$this->app->scoped(\Fomvasss\AiTasks\Support\TenantResolver::class, fn () => new MyTenantResolver());
```

A resolver only sees the current request/auth state, nothing task-specific — for that use `tenantId()` on the task.

### Tenant from a header

When the tenant comes from a gateway or a service-to-service call, name the header:

```dotenv
AI_TENANT_HEADER=X-Tenant-Id
```

> [!WARNING]
> Enable it only when the header is set by trusted infrastructure and stripped from client requests. Any caller can put any value there — billing another tenant's budget or dodging their own, and moving `ai_runs.tenant_id` with whatever is charged by it. Before 3.39 the resolver read `X-Tenant-Id` unconditionally.

## Subject

Tag the run with the record it concerns, to filter `ai_runs` by subject instead of only by tenant/task. Independent of `tenantId()`:

```php
protected function subjectType(): ?string
{
    return 'order';
}

protected function subjectId(): ?string
{
    return (string) $this->order->id;
}
```

## User

`ai_runs.user_id` records who started the run — for audit, spend per user and the dashboard's **User** filter. By default it is `auth()->id()` at dispatch (in the request, before the job is queued), so a run started with nobody logged in — a scheduled job, a webhook, a system task — records `null`. Override `userId()` when the task runs on someone's behalf without them being logged in:

```php
protected function userId(): ?string
{
    return (string) $this->comment->author_id;
}
```

It is unrelated to `tenantId()`: the tenant pays, the user started the run. Publish and run the migration (`vendor:publish --tag=ai-migrations`, `migrate`); until then runs are stored without the column.

## When the budget is exceeded

`Fomvasss\AiTasks\Exceptions\BudgetExceededException` is thrown on `send()`, `stream()` and in the queued job. The check runs twice:

- **pre-flight** — before the provider call, against prior spend
- **post-call** — after the response, adding its actual cost

A post-call rejection means the provider already billed the request: the run is recorded as `error` but keeps its real `cost` and tokens. Spend counts every run with a recorded cost regardless of status, so nothing vanishes from later checks.

The task's [`onFailed()`](queued-tasks.md#the-onfailed-hook) is called in both cases.

> [!NOTE]
> Budgets are a soft cap, not a hard guarantee. Concurrent jobs each pass the pre-flight check against the same prior spend, so several in-flight requests can overshoot the limit by up to their combined cost.
