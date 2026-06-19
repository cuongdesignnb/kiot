<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\SalaryAdvance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryAdvanceService
{
    public function __construct(private EmployeeSalaryLedgerService $ledger) {}

    public function create(Employee $employee, array $data, string $idempotencyKey): SalaryAdvance
    {
        return DB::transaction(function () use ($employee, $data, $idempotencyKey) {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if (! $lockedEmployee->is_active) {
                throw ValidationException::withMessages(['employee' => 'Không được tạo tạm ứng cho nhân viên đã nghỉ việc.']);
            }
            if ($existing = SalaryAdvance::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $advance = SalaryAdvance::create([
                'code' => null,
                'employee_id' => $lockedEmployee->id,
                'branch_id' => $data['branch_id'],
                'amount' => $data['amount'],
                'applied_amount' => 0,
                'remaining_amount' => $data['amount'],
                'advance_date' => $data['advance_date'],
                'payment_method' => $data['payment_method'],
                'status' => 'active',
                'note' => $data['note'],
                'created_by' => auth()->id(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $advance->update([
                'code' => 'TU'.str_pad((string) $advance->id, 6, '0', STR_PAD_LEFT),
            ]);

            $cashFlow = CashFlow::create([
                'code' => "PCTU{$advance->id}",
                'type' => 'payment',
                'amount' => $advance->amount,
                'time' => $advance->advance_date,
                'branch_id' => $advance->branch_id,
                'category' => 'Tạm ứng lương',
                'target_type' => 'employee',
                'target_id' => $lockedEmployee->id,
                'target_name' => $lockedEmployee->name,
                'reference_type' => 'SalaryAdvance',
                'reference_code' => $advance->code,
                'payment_method' => $advance->payment_method,
                'description' => $advance->note,
                'status' => 'active',
            ]);
            $advance->update(['cash_flow_id' => $cashFlow->id]);

            $this->ledger->append($lockedEmployee, [
                'code' => $advance->code,
                'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_ADVANCE,
                'reference_type' => 'salary_advance',
                'reference_id' => $advance->id,
                'amount' => -(int) $advance->amount,
                'event_at' => $advance->advance_date,
                'payment_method' => $advance->payment_method,
                'note' => $advance->note,
                'idempotency_key' => "salary_advance:{$advance->id}",
            ]);

            ActivityLog::log('salary_advance_create', "Tạo tạm ứng {$advance->code}", $advance);

            return $advance->fresh('cashFlow');
        });
    }

    public function cancel(SalaryAdvance $advance, string $reason, $eventAt): SalaryAdvance
    {
        return DB::transaction(function () use ($advance, $reason, $eventAt) {
            $locked = SalaryAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if ((int) $locked->applied_amount > 0) {
                throw ValidationException::withMessages([
                    'advance' => 'Không thể hủy tạm ứng đã được cấn vào phiếu lương.',
                ]);
            }

            $entry = EmployeeSalaryLedgerEntry::where('reference_type', 'salary_advance')
                ->where('reference_id', $locked->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_ADVANCE)
                ->firstOrFail();
            $this->ledger->reverse(
                $entry,
                "H{$locked->code}",
                $eventAt,
                $reason,
                "cancel_salary_advance:{$locked->id}"
            );

            if ($locked->cash_flow_id) {
                CashFlow::withTrashed()->whereKey($locked->cash_flow_id)->update([
                    'status' => 'cancelled',
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'deleted_at' => now(),
                ]);
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            ActivityLog::log('salary_advance_cancel', "Hủy tạm ứng {$locked->code}", $locked, ['reason' => $reason]);

            return $locked->fresh();
        });
    }
}
