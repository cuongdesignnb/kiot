<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeSalaryLedgerService
{
    public function append(Employee $employee, array $data): EmployeeSalaryLedgerEntry
    {
        return DB::transaction(function () use ($employee, $data) {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (! empty($data['idempotency_key'])) {
                $existing = EmployeeSalaryLedgerEntry::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $entry = EmployeeSalaryLedgerEntry::create([
                ...$data,
                'employee_id' => $lockedEmployee->id,
                'branch_id' => $data['branch_id'] ?? $lockedEmployee->branch_id,
                'is_effective' => $data['is_effective'] ?? true,
                'status' => $data['status'] ?? 'valid',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $this->rebuildLocked($lockedEmployee);

            return $entry->fresh();
        });
    }

    public function reverse(
        EmployeeSalaryLedgerEntry $entry,
        string $code,
        $eventAt,
        string $reason,
        ?string $idempotencyKey = null
    ): EmployeeSalaryLedgerEntry {
        return DB::transaction(function () use ($entry, $code, $eventAt, $reason, $idempotencyKey) {
            $original = EmployeeSalaryLedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $employee = Employee::query()->lockForUpdate()->findOrFail($original->employee_id);

            $existing = EmployeeSalaryLedgerEntry::where('original_entry_id', $original->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)
                ->first();
            if ($existing) {
                return $existing;
            }

            $original->update([
                'status' => 'reversed',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $reversal = EmployeeSalaryLedgerEntry::create([
                'employee_id' => $original->employee_id,
                'branch_id' => $original->branch_id,
                'paysheet_id' => $original->paysheet_id,
                'payslip_id' => $original->payslip_id,
                'original_entry_id' => $original->id,
                'code' => $code,
                'type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE,
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'amount' => -$original->amount,
                'balance_after' => 0,
                'is_effective' => true,
                'status' => 'valid',
                'event_at' => $eventAt,
                'payment_method' => $original->payment_method,
                'note' => "Đảo {$original->code}",
                'reason' => $reason,
                'created_by' => auth()->id(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->rebuildLocked($employee);

            return $reversal->fresh();
        });
    }

    public function rebuild(Employee $employee, bool $writeAudit = true): int
    {
        return DB::transaction(function () use ($employee, $writeAudit) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $balance = $this->rebuildLocked($locked);

            if ($writeAudit) {
                ActivityLog::log(
                    'payroll_balance_rebuild',
                    "Tính lại số dư lương nhân viên {$locked->code}",
                    $locked,
                    ['balance' => $balance]
                );
            }

            return $balance;
        });
    }

    public function currentBalance(int $employeeId): int
    {
        // Invariant: status is never used to decide whether an entry affects balance.
        return (int) EmployeeSalaryLedgerEntry::where('employee_id', $employeeId)
            ->where('is_effective', true)
            ->sum('amount');
    }

    public function timeline(Employee $employee, array $filters): array
    {
        $base = EmployeeSalaryLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('is_effective', true);

        if (! empty($filters['branch_id'])) {
            $base->where('branch_id', $filters['branch_id']);
        }

        $from = isset($filters['from_date'])
            ? Carbon::parse($filters['from_date'])->startOfDay()
            : null;
        $to = isset($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->endOfDay()
            : null;
        $openingBalance = $from
            ? (int) (clone $base)->where('event_at', '<', $from)->sum('amount')
            : 0;

        $summaryPeriod = (clone $base)
            ->when($from, fn ($q) => $q->where('event_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('event_at', '<=', $to));

        $period = (clone $summaryPeriod)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($inner) => $inner
                    ->where('code', 'like', "%{$keyword}%")
                    ->orWhere('note', 'like', "%{$keyword}%")
                    ->orWhere('reason', 'like', "%{$keyword}%")
                    ->orWhereHas('creator', fn ($creator) => $creator->where('name', 'like', "%{$keyword}%")));
            });

        $summaryQuery = clone $summaryPeriod;
        $increase = (int) (clone $summaryQuery)->where('amount', '>', 0)->sum('amount');
        $decreaseSigned = (int) (clone $summaryQuery)->where('amount', '<', 0)->sum('amount');
        $netChange = (int) (clone $summaryQuery)->sum('amount');
        $filteredIncrease = (int) (clone $period)->where('amount', '>', 0)->sum('amount');
        $filteredDecrease = (int) (clone $period)->where('amount', '<', 0)->sum('amount');
        $filteredNetChange = (int) (clone $period)->sum('amount');

        $rows = $period->with(['creator:id,name', 'employee:id,code,name', 'paysheet:id,code,name', 'payslip:id,code'])
            ->orderBy('event_at')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return [
            'data' => $rows,
            'summary' => [
                'opening_balance' => $openingBalance,
                'total_increase' => $increase,
                'total_decrease' => abs($decreaseSigned),
                'net_change' => $netChange,
                'current_balance' => $openingBalance + $netChange,
            ],
            'filtered_summary' => [
                'filtered_increase' => $filteredIncrease,
                'filtered_decrease' => abs($filteredDecrease),
                'filtered_net_change' => $filteredNetChange,
            ],
        ];
    }

    public function filteredEntries(Employee $employee, array $filters)
    {
        $filters['per_page'] = 100;
        $query = EmployeeSalaryLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('is_effective', true)
            ->when($filters['from_date'] ?? null, fn ($q, $from) => $q->where('event_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to_date'] ?? null, fn ($q, $to) => $q->where('event_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['branch_id'] ?? null, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($inner) => $inner
                    ->where('code', 'like', "%{$keyword}%")
                    ->orWhere('note', 'like', "%{$keyword}%")
                    ->orWhere('reason', 'like', "%{$keyword}%")
                    ->orWhereHas('creator', fn ($creator) => $creator->where('name', 'like', "%{$keyword}%")));
            });

        return $query->with(['creator:id,name', 'employee:id,code,name', 'paysheet:id,code,name', 'payslip:id,code'])
            ->orderBy('event_at')
            ->orderBy('id')
            ->cursor();
    }

    private function rebuildLocked(Employee $employee): int
    {
        $balance = 0;
        EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)
            ->where('is_effective', true)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->each(function (EmployeeSalaryLedgerEntry $entry) use (&$balance) {
                $balance += (int) $entry->amount;
                if ((int) $entry->balance_after !== $balance) {
                    $entry->updateQuietly(['balance_after' => $balance]);
                }
            });

        $employee->updateQuietly([
            'salary_balance_cache' => $balance,
            'salary_balance_calculated_at' => now(),
        ]);

        return $balance;
    }
}
