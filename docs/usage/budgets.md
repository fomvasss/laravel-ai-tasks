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

Current spend vs limit — [`ai:budget`](../reference/commands.md#aibudget).

## How the tenant is resolved

1. `tenantId()` on the task, if it returns non-null
2. `TenantResolver`:
   1. `X-Tenant-Id` request header
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

> [!WARNING]
> The default resolver trusts the client-supplied `X-Tenant-Id` header: any caller can bill another tenant's budget, or dodge their own, by setting it. If budgets matter and the header isn't set by trusted infrastructure only, bind a resolver that derives the tenant from the authenticated user.

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

## When the budget is exceeded

`Fomvasss\AiTasks\Exceptions\BudgetExceededException` is thrown on `send()`, `stream()` and in the queued job. The check runs twice:

- **pre-flight** — before the provider call, against prior spend
- **post-call** — after the response, adding its actual cost

A post-call rejection means the provider already billed the request: the run is recorded as `error` but keeps its real `cost` and tokens. Spend counts every run with a recorded cost regardless of status, so nothing vanishes from later checks.

The task's [`onFailed()`](queued-tasks.md#the-onfailed-hook) is called in both cases.

> [!NOTE]
> Budgets are a soft cap, not a hard guarantee. Concurrent jobs each pass the pre-flight check against the same prior spend, so several in-flight requests can overshoot the limit by up to their combined cost.
