<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPostingService
{
    public function __construct(private EmployeeSalaryLedgerService $ledger) {}

    public function lock(Paysheet $paysheet): Paysheet
    {
        return DB::transaction(function () use ($paysheet) {
            $sheet = Paysheet::with('payslips')->lockForUpdate()->findOrFail($paysheet->id);
            if ($sheet->status === 'locked') {
                return $sheet;
            }
            if ($sheet->status !== 'calculated') {
                throw ValidationException::withMessages(['status' => 'Chỉ bảng lương tạm tính mới được chốt.']);
            }

            foreach ($sheet->payslips as $slip) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($slip->employee_id);
                $this->ledger->append($employee, [
                    'paysheet_id' => $sheet->id,
                    'payslip_id' => $slip->id,
                    'code' => $slip->code,
                    'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
                    'reference_type' => 'payslip',
                    'reference_id' => $slip->id,
                    'amount' => (int) $slip->total_salary,
                    'event_at' => now(),
                    'note' => $sheet->name,
                    'idempotency_key' => "payroll_accrual:{$slip->id}",
                ]);
                $this->applyAdvancesFifo($sheet, $slip);
            }

            $sheet->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => auth()->user()?->name ?? 'System',
            ]);
            $sheet->recalculateTotals();

            ActivityLog::log('paysheet_lock', "Chốt bảng lương {$sheet->code}", $sheet);

            return $sheet->fresh('payslips');
        });
    }

    public function cancel(Paysheet $paysheet, string $reason, $eventAt): Paysheet
    {
        return DB::transaction(function () use ($paysheet, $reason, $eventAt) {
            $sheet = Paysheet::with('payslips')->lockForUpdate()->findOrFail($paysheet->id);
            if ($sheet->status === 'cancelled') {
                return $sheet;
            }
            if ($sheet->payments()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages([
                    'payments' => 'Phải hủy toàn bộ thanh toán hợp lệ trước khi hủy bảng lương.',
                ]);
            }

            foreach ($sheet->payslips as $slip) {
                $accrual = EmployeeSalaryLedgerEntry::where('payslip_id', $slip->id)
                    ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
                    ->first();
                if ($accrual) {
                    $this->ledger->reverse(
                        $accrual,
                        "H{$slip->code}",
                        $eventAt,
                        $reason,
                        "cancel_payroll_accrual:{$accrual->id}"
                    );
                }

                $applications = SalaryAdvanceApplication::where('payslip_id', $slip->id)
                    ->where('status', 'active')->lockForUpdate()->get();
                foreach ($applications as $application) {
                    $advance = SalaryAdvance::lockForUpdate()->findOrFail($application->salary_advance_id);
                    $advance->applied_amount -= $application->amount;
                    $advance->remaining_amount += $application->amount;
                    $advance->status = $advance->applied_amount > 0 ? 'partially_applied' : 'active';
                    $advance->save();
                    $application->update([
                        'status' => 'cancelled',
                        'cancelled_by' => auth()->id(),
                        'cancelled_at' => now(),
                    ]);
                }
                $slip->update([
                    'applied_advance' => 0,
                    'paid_amount' => 0,
                    'remaining' => (int) $slip->total_salary,
                    'payment_status' => 'unpaid',
                ]);
            }

            $sheet->update(['status' => 'cancelled', 'payment_status' => 'unpaid']);
            ActivityLog::log('paysheet_cancel', "Hủy bảng lương {$sheet->code}", $sheet, ['reason' => $reason]);

            return $sheet->fresh();
        });
    }

    private function applyAdvancesFifo(Paysheet $sheet, $slip): void
    {
        $remainingSalary = (int) $slip->total_salary;
        $advances = SalaryAdvance::where('employee_id', $slip->employee_id)
            ->whereIn('status', ['active', 'partially_applied'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('advance_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $applied = 0;
        foreach ($advances as $advance) {
            if ($remainingSalary <= 0) {
                break;
            }
            $amount = min((int) $advance->remaining_amount, $remainingSalary);
            SalaryAdvanceApplication::firstOrCreate(
                ['salary_advance_id' => $advance->id, 'payslip_id' => $slip->id],
                [
                    'employee_id' => $slip->employee_id,
                    'paysheet_id' => $sheet->id,
                    'amount' => $amount,
                    'status' => 'active',
                    'created_by' => auth()->id(),
                ]
            );
            $advance->applied_amount += $amount;
            $advance->remaining_amount -= $amount;
            $advance->status = $advance->remaining_amount > 0 ? 'partially_applied' : 'applied';
            $advance->save();
            $remainingSalary -= $amount;
            $applied += $amount;
        }

        $slip->applied_advance = $applied;
        $slip->remaining = max((int) $slip->total_salary - $applied - (int) $slip->paid_amount, 0);
        $slip->payment_status = $slip->remaining === 0 ? 'paid' : ($applied > 0 ? 'partial' : 'unpaid');
        $slip->save();
    }
}
