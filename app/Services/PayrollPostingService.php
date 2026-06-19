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
                throw ValidationException::withMessages(['status' => 'Chi bang luong tam tinh moi duoc chot.']);
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

            ActivityLog::log('paysheet_lock', "Chot bang luong {$sheet->code}", $sheet);

            return $sheet->fresh('payslips');
        });
    }

    public function cancel(Paysheet $paysheet, string $reason, $eventAt): array
    {
        return DB::transaction(function () use ($paysheet, $reason, $eventAt) {
            $sheet = Paysheet::with('payslips')->lockForUpdate()->findOrFail($paysheet->id);
            if ($sheet->status === 'cancelled') {
                return [
                    'paysheet' => $sheet->fresh(),
                    'reversed_entries_count' => $this->payrollAccrualReversalCount($sheet),
                ];
            }
            if ($sheet->status !== 'locked') {
                throw ValidationException::withMessages([
                    'status' => 'Chi bang luong da chot moi duoc huy bang dong dao.',
                ]);
            }
            if ($sheet->payments()->where('status', 'active')->where('amount', '>', 0)->exists()) {
                throw ValidationException::withMessages([
                    'payments' => 'Bang luong nay da co phieu thanh toan. Vui long huy cac phieu thanh toan truoc khi huy bang luong.',
                ]);
            }

            $accruals = EmployeeSalaryLedgerEntry::query()
                ->where('paysheet_id', $sheet->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
                ->where('is_effective', true)
                ->orderBy('id')
                ->get();

            if ($accruals->isEmpty()) {
                if (! $this->canUseLegacyMissingAccrualCancel($sheet)) {
                    throw ValidationException::withMessages([
                        'ledger' => 'Bang luong thieu payroll_accrual va khong du dieu kien huy tu dong. Vui long chay audit doi soat.',
                    ]);
                }

                $reversedEntries = $this->cancelLegacyMissingAccrualPaysheet($sheet, $reason, $eventAt);

                ActivityLog::log('paysheet_cancel', "Huy bang luong legacy thieu accrual {$sheet->code}", $sheet, [
                    'reason' => $reason,
                    'mode' => 'legacy_missing_payroll_accrual',
                    'reversed_entries_count' => $reversedEntries,
                ]);

                return [
                    'paysheet' => $sheet->fresh('payslips'),
                    'reversed_entries_count' => $reversedEntries,
                    'mode' => 'legacy_missing_payroll_accrual',
                ];
            }

            foreach ($sheet->payslips as $slip) {
                foreach ($accruals->where('payslip_id', $slip->id) as $accrual) {
                    $this->ledger->reverse(
                        $accrual,
                        "H{$slip->code}",
                        $eventAt,
                        $reason,
                        "cancel:paysheet:{$sheet->id}:payroll_accrual:{$accrual->id}"
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
                    'remaining' => 0,
                    'payment_status' => 'unpaid',
                ]);
            }

            $sheet->update(['status' => 'cancelled', 'payment_status' => 'unpaid']);
            $sheet->recalculateTotals();
            ActivityLog::log('paysheet_cancel', "Huy bang luong {$sheet->code}", $sheet, ['reason' => $reason]);

            return [
                'paysheet' => $sheet->fresh('payslips'),
                'reversed_entries_count' => $this->payrollAccrualReversalCount($sheet),
                'mode' => 'standard',
            ];
        });
    }

    public function canCancel(Paysheet $paysheet): array
    {
        $sheet = Paysheet::withCount([
            'payslips',
            'payments as active_payment_count' => fn ($query) => $query->where('status', 'active')->where('amount', '>', 0),
        ])->findOrFail($paysheet->id);

        $payrollAccrualCount = EmployeeSalaryLedgerEntry::query()
            ->where('paysheet_id', $sheet->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
            ->where('is_effective', true)
            ->count();
        $salaryPaymentCount = EmployeeSalaryLedgerEntry::query()
            ->where('paysheet_id', $sheet->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('is_effective', true)
            ->count();
        $cancelReverseCount = $this->payrollAccrualReversalCount($sheet);
        $employeeCount = $sheet->payslips()->distinct('employee_id')->count('employee_id');

        $canCancel = true;
        $reason = 'ok';
        $mode = 'standard';
        $requiresLegacyZeroNetReversal = false;
        if ($sheet->status === 'cancelled') {
            $canCancel = false;
            $reason = 'already_cancelled';
            $mode = 'already_cancelled';
        } elseif ($sheet->status !== 'locked') {
            $canCancel = false;
            $reason = 'not_locked';
            $mode = 'blocked';
        } elseif ((int) $sheet->active_payment_count > 0) {
            $canCancel = false;
            $reason = 'has_active_payment';
            $mode = 'blocked';
        } elseif ($payrollAccrualCount === 0) {
            if ((int) $sheet->payslips_count > 0 && $cancelReverseCount === 0) {
                $canCancel = true;
                $reason = 'legacy_can_cancel_no_active_payment';
                $mode = 'legacy_missing_payroll_accrual';
                $requiresLegacyZeroNetReversal = true;
            } else {
                $canCancel = false;
                $reason = 'legacy_missing_accrual_requires_manual_audit';
                $mode = 'manual_audit_required';
            }
        }

        return [
            'paysheet_code' => $sheet->code,
            'paysheet_status' => $sheet->status,
            'total_salary' => (int) $sheet->total_salary,
            'paid_amount' => (int) $sheet->total_paid,
            'remaining_amount' => (int) $sheet->total_remaining,
            'payslip_count' => (int) $sheet->payslips_count,
            'active_payment_count' => (int) $sheet->active_payment_count,
            'payroll_accrual_count' => $payrollAccrualCount,
            'salary_payment_count' => $salaryPaymentCount,
            'cancel_reverse_count' => $cancelReverseCount,
            'employee_count' => $employeeCount,
            'can_cancel' => $canCancel ? 'yes' : 'no',
            'mode' => $mode,
            'reason' => $reason,
            'requires_legacy_zero_net_reversal' => $requiresLegacyZeroNetReversal,
        ];
    }

    private function canUseLegacyMissingAccrualCancel(Paysheet $sheet): bool
    {
        if ($sheet->status !== 'locked') {
            return false;
        }
        if ($sheet->payslips->isEmpty()) {
            return false;
        }
        if ($sheet->payments()->where('status', 'active')->where('amount', '>', 0)->exists()) {
            return false;
        }

        $payrollAccrualCount = EmployeeSalaryLedgerEntry::query()
            ->where('paysheet_id', $sheet->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
            ->where('is_effective', true)
            ->count();
        if ($payrollAccrualCount > 0) {
            return false;
        }

        return ! EmployeeSalaryLedgerEntry::query()
            ->where('paysheet_id', $sheet->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
            ->where('idempotency_key', 'like', "legacy:paysheet_cancel:accrual:{$sheet->id}:%")
            ->exists();
    }

    private function cancelLegacyMissingAccrualPaysheet(Paysheet $sheet, string $reason, $eventAt): int
    {
        $reversedEntries = 0;

        foreach ($sheet->payslips as $slip) {
            $amount = (int) $slip->total_salary;
            if ($amount <= 0) {
                continue;
            }

            $employee = Employee::query()->lockForUpdate()->findOrFail($slip->employee_id);
            $accrual = $this->ledger->append($employee, [
                'paysheet_id' => $sheet->id,
                'payslip_id' => $slip->id,
                'code' => "LEGACY-{$slip->code}",
                'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
                'reference_type' => 'payslip',
                'reference_id' => $slip->id,
                'amount' => $amount,
                'event_at' => $eventAt,
                'note' => "Legacy backfill payroll accrual before cancel paysheet {$sheet->code}: {$reason}",
                'idempotency_key' => "legacy:paysheet_cancel:accrual:{$sheet->id}:{$slip->id}",
            ]);

            $this->ledger->reverse(
                $accrual,
                "HLEGACY-{$slip->code}",
                $eventAt,
                $reason,
                "legacy:paysheet_cancel:reverse:{$sheet->id}:{$slip->id}"
            );
            $reversedEntries++;

            $slip->update([
                'applied_advance' => 0,
                'paid_amount' => 0,
                'remaining' => 0,
                'payment_status' => 'unpaid',
            ]);
        }

        $sheet->update(['status' => 'cancelled', 'payment_status' => 'unpaid']);
        $sheet->recalculateTotals();

        return $reversedEntries;
    }

    private function payrollAccrualReversalCount(Paysheet $sheet): int
    {
        $accrualIds = EmployeeSalaryLedgerEntry::query()
            ->where('paysheet_id', $sheet->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
            ->pluck('id');

        if ($accrualIds->isEmpty()) {
            return 0;
        }

        return EmployeeSalaryLedgerEntry::query()
            ->whereIn('original_entry_id', $accrualIds)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)
            ->where('is_effective', true)
            ->count();
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
