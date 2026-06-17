<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Support\Budget;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AiBudgetCommand extends Command
{
    protected $signature = 'ai:budget
        {tenant=default : Tenant ID}
        {--month= : Period in YYYY-MM format (default: current month)}';

    protected $description = 'Show AI spend vs budget for a tenant';

    public function handle(Budget $budget): int
    {
        $tenant = (string) $this->argument('tenant');
        $when   = $this->parseMonth($this->option('month'));

        $limit = $budget->getMonthlyLimit($tenant);
        $spent = $budget->getMonthlySpent($tenant, $when);
        $left  = $budget->getMonthlyRemaining($tenant, $when);

        $this->table(
            ['Tenant', 'Limit (USD)', 'Spent (USD)', 'Remaining (USD)', 'Month'],
            [[
                $tenant,
                $limit === null ? '—' : number_format($limit, 4, '.', ''),
                number_format($spent, 4, '.', ''),
                $left === null ? '—' : number_format($left, 4, '.', ''),
                $when->format('Y-m'),
            ]]
        );

        if ($limit !== null && $left !== null && $left <= 0) {
            $this->error('Budget exhausted.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parseMonth(?string $month): Carbon
    {
        if ($month === null) {
            return now();
        }

        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Exception) {
            $this->error("Invalid month format: \"{$month}\". Use YYYY-MM.");
            exit(self::FAILURE);
        }
    }
}
