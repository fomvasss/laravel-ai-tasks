<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Exceptions\BudgetExceededException;
use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Support\Carbon;

class Budget
{
    public function getMonthlyLimit(string $tenantId): ?float
    {
        $conf = config('ai.budgets', []);

        return isset($conf[$tenantId]['monthly_usd'])
            ? (float) $conf[$tenantId]['monthly_usd']
            : (isset($conf['default']['monthly_usd']) ? (float) $conf['default']['monthly_usd'] : null);
    }

    public function getMonthlySpent(string $tenantId, ?Carbon $when = null): float
    {
        $when = $when ?? now();

        return $this->getSpentBetween($tenantId, $when->copy()->startOfMonth(), $when->copy()->endOfMonth());
    }

    public function getSpentBetween(string $tenantId, Carbon $from, Carbon $to): float
    {
        $sum = AiRun::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'ok')
            ->whereNotNull('cost')
            ->sum('cost');

        return round((float) $sum, 8);
    }

    public function getMonthlyRemaining(string $tenantId, ?Carbon $when = null): ?float
    {
        $limit = $this->getMonthlyLimit($tenantId);
        if ($limit === null) {
            return null;
        }

        return max(0.0, round($limit - $this->getMonthlySpent($tenantId, $when), 8));
    }

    public function ensureNotExceeded(string $tenantId, float $expectedCost = 0.0): void
    {
        $limit = $this->getMonthlyLimit($tenantId);
        if ($limit === null) {
            return;
        }

        $remaining = $this->getMonthlyRemaining($tenantId);
        if ($remaining !== null && $remaining < $expectedCost) {
            throw new BudgetExceededException(
                "Budget exceeded for tenant [{$tenantId}]: remaining \${$remaining}, expected \${$expectedCost}"
            );
        }
    }
}
