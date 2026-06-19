<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Services\PayrollLedgerService;
use Illuminate\Console\Command;

class RebuildSalaryBalances extends Command
{
    protected $signature = 'payroll:rebuild-salary-balances
        {--employee-code= : Rebuild one employee only}
        {--dry-run : Calculate differences without writing balance_after or cache}';

    protected $description = 'Rebuild employee salary balance caches from effective payroll ledger entries';

    public function handle(PayrollLedgerService $ledger): int
    {
        $query = Employee::query()->orderBy('id');
        $employeeCode = trim((string) $this->option('employee-code'));

        if ($employeeCode !== '') {
            $query->where('code', $employeeCode);
        }

        $employees = $query->get();
        if ($employeeCode !== '' && $employees->isEmpty()) {
            $this->error("Employee code {$employeeCode} was not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($employees as $employee) {
            $effectiveBalance = (int) EmployeeSalaryLedgerEntry::query()
                ->where('employee_id', $employee->id)
                ->where('is_effective', true)
                ->sum('amount');
            $cacheBefore = (int) $employee->salary_balance_cache;
            $cacheAfter = $dryRun
                ? $effectiveBalance
                : (int) $ledger->rebuildEmployeeBalance($employee->id);

            $rows[] = [
                $employee->code,
                $effectiveBalance,
                $cacheBefore,
                $cacheAfter,
                $dryRun ? 'DRY-RUN' : 'REBUILT',
            ];
        }

        $this->table(
            ['Employee code', 'Effective ledger balance', 'Cache before', 'Cache after', 'Result'],
            $rows
        );
        $this->info('Source of truth: SUM(amount WHERE is_effective = true).');
        $this->info('employees.balance and ledger amount were not changed.');

        return self::SUCCESS;
    }
}
