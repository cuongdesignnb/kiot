<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;

class PayrollLedgerService
{
    public function __construct(private EmployeeSalaryLedgerService $ledger) {}

    public function currentBalance(int $employeeId): float
    {
        return (float) $this->ledger->currentBalance($employeeId);
    }

    public function appendEntry(array $data): EmployeeSalaryLedgerEntry
    {
        $employeeId = $data['employee_id'] ?? null;
        if (! $employeeId) {
            throw new \InvalidArgumentException('employee_id is required.');
        }

        $employee = Employee::query()->findOrFail($employeeId);
        unset($data['employee_id']);

        return $this->ledger->append($employee, $data);
    }

    public function rebuildEmployeeBalance(int $employeeId): float
    {
        $employee = Employee::query()->findOrFail($employeeId);

        return (float) $this->ledger->rebuild($employee);
    }

    public function rebuildAllBalances(): array
    {
        $balances = [];

        Employee::query()->orderBy('id')->each(function (Employee $employee) use (&$balances) {
            $balances[$employee->id] = $this->rebuildEmployeeBalance($employee->id);
        });

        return $balances;
    }
}
