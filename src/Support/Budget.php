<?php

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Support\Carbon;

class Budget
{
    public function getMonthlyLimit(string $tenantId): ?float
    {
        $conf = config('ai.budgets');
        if (isset($conf[$tenantId]['monthly_usd'])) {
            return (float)$conf[$tenantId]['monthly_usd'];
        }

        if (isset($conf['default']['monthly_usd'])) {
            return (float)$conf['default']['monthly_usd'];
        }

        return null; // без ліміту
    }

    public function getMonthlySpent(string $tenantId, ?Carbon $when = null): float
    {
        $when = $when ?: now();
        $from = $when->copy()->startOfMonth();
        $to   = $when->copy()->endOfMonth();

        // usage у нас casted array — сумуємо в PHP, сумісно з усіма БД
        $runs = AiRun::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'ok')
            ->get(['usage']);

        $sum = 0.0;
        foreach ($runs as $r) {
            $sum += (float)($r->usage['cost'] ?? 0.0);
        }
        return round($sum, 8);
    }

    public function getMonthlyRemaining(string $tenantId): ?float
    {
        $limit = $this->getMonthlyLimit($tenantId);
        if ($limit === null) return null;

        $spent = $this->getMonthlySpent($tenantId);
        
        return max(0.0, round($limit - $spent, 8));
    }

    /**
     * Кидає виняток, якщо ліміт вичерпано (з урахуванням очікуваних витрат $expectedCost)
     */
    public function ensureNotExceeded(string $tenantId, float $expectedCost = 0.0): void
    {
        $limit = $this->getMonthlyLimit($tenantId);
        if ($limit === null) return; // без бюджету

        $remaining = $this->getMonthlyRemaining($tenantId);
        if ($remaining !== null && $remaining < $expectedCost) {
            throw new \Fomvasss\AiTasks\Support\BudgetExceededException(
                "Budget exceeded for tenant [{$tenantId}]: remaining \${$remaining}, expected \${$expectedCost}"
            );
        }
    }
}