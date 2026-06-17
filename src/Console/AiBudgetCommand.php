<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Console;

use Fomvasss\AiTasks\Support\Budget;
use Illuminate\Console\Command;

class AiBudgetCommand extends Command
{
    protected $signature = 'ai:budget {tenant=default : Tenant ID}';

    protected $description = 'Show current month AI spend vs budget for a tenant';

    public function handle(Budget $budget): int
    {
        $tenant = (string) $this->argument('tenant');

        $limit = $budget->getMonthlyLimit($tenant);
        $spent = $budget->getMonthlySpent($tenant);
        $left  = $budget->getMonthlyRemaining($tenant);

        $this->table(
            ['Tenant', 'Limit (USD)', 'Spent (USD)', 'Remaining (USD)', 'Month'],
            [[
                $tenant,
                $limit === null ? '—' : number_format($limit, 4, '.', ''),
                number_format($spent, 4, '.', ''),
                $left === null ? '—' : number_format($left, 4, '.', ''),
                now()->format('Y-m'),
            ]]
        );

        if ($limit !== null && $left !== null && $left <= 0) {
            $this->error('Budget exhausted.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
