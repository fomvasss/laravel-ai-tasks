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
        {--month=      : Month in YYYY-MM format}
        {--from=       : Start date YYYY-MM-DD}
        {--to=         : End date YYYY-MM-DD (default: today)}';

    protected $description = 'Show AI spend vs budget for a tenant';

    public function handle(Budget $budget): int
    {
        $tenant = (string) $this->argument('tenant');

        [$from, $to, $isCustomRange] = $this->parsePeriod();

        $spent = $budget->getSpentBetween($tenant, $from, $to);

        $limit = $isCustomRange ? null : $budget->getMonthlyLimit($tenant);
        $left  = $isCustomRange ? null : $budget->getMonthlyRemaining($tenant, $from);

        $this->table(
            ['Tenant', 'Period', 'Spent (USD)', 'Limit (USD)', 'Remaining (USD)'],
            [[
                $tenant,
                $from->format('Y-m-d') . ' → ' . $to->format('Y-m-d'),
                number_format($spent, 6, '.', ''),
                $limit === null ? '—' : number_format($limit, 4, '.', ''),
                $left  === null ? '—' : number_format($left, 4, '.', ''),
            ]]
        );

        if (!$isCustomRange && $limit !== null && $left !== null && $left <= 0) {
            $this->error('Budget exhausted.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parsePeriod(): array
    {
        $fromOpt = $this->option('from');
        $toOpt   = $this->option('to');
        $month   = $this->option('month');

        if ($fromOpt !== null) {
            $from = $this->parseDate($fromOpt, 'from');
            $to   = $toOpt !== null ? $this->parseDate($toOpt, 'to')->endOfDay() : now()->endOfDay();
            return [$from, $to, true];
        }

        $when = $month !== null ? $this->parseMonth($month) : now();
        return [$when->copy()->startOfMonth(), $when->copy()->endOfMonth(), false];
    }

    private function parseMonth(string $month): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Exception) {
            $this->error("Invalid month: \"{$month}\". Use YYYY-MM.");
            exit(self::FAILURE);
        }
    }

    private function parseDate(string $date, string $label): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Exception) {
            $this->error("Invalid --{$label}: \"{$date}\". Use YYYY-MM-DD.");
            exit(self::FAILURE);
        }
    }
}
