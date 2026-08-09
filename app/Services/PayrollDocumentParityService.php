<?php

namespace App\Services;

use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only semantic identity checks for payroll documents and their ledger rows.
 *
 * Idempotency keys are an implementation detail. Reconciliation and backfill
 * must identify the business document by its employee, paysheet, payslip,
 * ledger type and reference identity.
 */
class PayrollDocumentParityService
{
    public function accrualEntries(Payslip $slip): Collection
    {
        return EmployeeSalaryLedgerEntry::query()
            ->where('employee_id', $slip->employee_id)
            ->where('paysheet_id', $slip->paysheet_id)
            ->where('payslip_id', $slip->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)
            ->where('reference_type', 'payslip')
            ->where('reference_id', $slip->id)
            ->where('is_effective', true)
            ->orderBy('id')
            ->get();
    }

    public function paymentEntries(PaysheetPayment $payment): Collection
    {
        return EmployeeSalaryLedgerEntry::query()
            ->where('employee_id', $payment->employee_id)
            ->where('paysheet_id', $payment->paysheet_id)
            ->where('payslip_id', $payment->payslip_id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('reference_type', 'paysheet_payment')
            ->where('reference_id', $payment->id)
            ->where('is_effective', true)
            ->orderBy('id')
            ->get();
    }

    public function classifyAccrual(Payslip $slip): array
    {
        $entries = $this->accrualEntries($slip);
        $expected = (int) $slip->total_salary;
        $actual = (int) $entries->sum('amount');
        $classification = match (true) {
            $entries->isEmpty() && $expected <= 0 => 'ZERO_SALARY',
            $entries->isEmpty() => 'MISSING',
            $entries->count() > 1 => 'DUPLICATE',
            $actual !== $expected => 'AMOUNT_MISMATCH',
            $expected === 0 => 'EXACT_ZERO',
            default => 'EXACT',
        };

        return [
            'classification' => $classification,
            'expected_amount' => $expected,
            'actual_amount' => $actual,
            'entry_count' => $entries->count(),
            'entries' => $entries,
        ];
    }

    /**
     * Classify a cancelled payment without offering an automatic repair path.
     *
     * A cancelled payment is valid only when its original salary-payment
     * ledger entry has been reversed and exactly one effective cancel_reverse
     * points back to that original entry.
     */
    public function classifyCancelledPayment(PaysheetPayment $payment): array
    {
        $expectedOriginalAmount = -(int) $payment->amount;
        $originals = EmployeeSalaryLedgerEntry::query()
            ->where('employee_id', $payment->employee_id)
            ->where('paysheet_id', $payment->paysheet_id)
            ->where('payslip_id', $payment->payslip_id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('reference_type', 'paysheet_payment')
            ->where('reference_id', $payment->id)
            ->where('is_effective', true)
            ->orderBy('id')
            ->get();

        $anomalies = [];
        $original = $originals->first();
        if ($originals->isEmpty()) {
            $anomalies[] = 'CANCELLED_MISSING_ORIGINAL_PAYMENT_LEDGER';
        } elseif ($originals->count() > 1) {
            $anomalies[] = 'CANCELLED_DUPLICATE_ORIGINAL_PAYMENT_LEDGER';
        } elseif ((int) $original->amount !== $expectedOriginalAmount || $original->status !== 'reversed') {
            $anomalies[] = 'CANCELLED_ORIGINAL_AMOUNT_MISMATCH';
        }

        $reversals = collect();
        if ($original) {
            $reversals = EmployeeSalaryLedgerEntry::query()
                ->where('original_entry_id', $original->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)
                ->where('is_effective', true)
                ->orderBy('id')
                ->get();

            $expectedReversalAmount = (int) $payment->amount;
            if ($reversals->isEmpty()) {
                $anomalies[] = 'CANCELLED_MISSING_REVERSAL';
            } elseif ($reversals->count() > 1) {
                $anomalies[] = 'CANCELLED_DUPLICATE_REVERSAL';
            } elseif ((int) $reversals->first()->amount !== $expectedReversalAmount) {
                $anomalies[] = 'CANCELLED_REVERSAL_AMOUNT_MISMATCH';
            }
        }

        return [
            'classification' => $anomalies[0] ?? 'CANCELLED_EXACT',
            'anomalies' => $anomalies,
            'original' => $original,
            'original_count' => $originals->count(),
            'reversals' => $reversals,
            'reversal_count' => $reversals->count(),
            'expected_original_amount' => $expectedOriginalAmount,
            'actual_original_amount' => $originals->sum('amount'),
            'expected_reversal_amount' => (int) $payment->amount,
            'actual_reversal_amount' => $reversals->sum('amount'),
        ];
    }

    public function classifyPayment(PaysheetPayment $payment): array
    {
        $entries = $this->paymentEntries($payment);
        $expected = -abs((int) $payment->amount);
        $actual = (int) $entries->sum('amount');
        $classification = match (true) {
            $entries->isEmpty() => 'MISSING',
            $entries->count() > 1 => 'DUPLICATE',
            $actual !== $expected => 'AMOUNT_MISMATCH',
            default => 'EXACT',
        };

        return [
            'classification' => $classification,
            'expected_amount' => $expected,
            'actual_amount' => $actual,
            'entry_count' => $entries->count(),
            'entries' => $entries,
        ];
    }

    public function lockedPayslips(array $filters = []): Collection
    {
        return Payslip::query()
            ->with(['employee:id,code,name,branch_id', 'paysheet:id,code,status,locked_at,period_start,total_paid,total_remaining'])
            ->whereHas('paysheet', fn (Builder $query) => $query->where('status', 'locked'))
            ->when($filters['employee-code'] ?? null, fn (Builder $query, string $code) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('code', $code)))
            ->when($filters['employee'] ?? null, function (Builder $query, $employee) {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('id', $employee)
                    ->orWhere('code', $employee));
            })
            ->when($filters['branch'] ?? null, fn (Builder $query, $branch) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('branch_id', $branch)))
            ->orderBy('paysheet_id')
            ->orderBy('id')
            ->get();
    }

    public function activePayments(array $filters = []): Collection
    {
        return $this->paymentQuery($filters)
            ->where('status', 'active')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    public function cancelledPayments(array $filters = []): Collection
    {
        return $this->paymentQuery($filters)
            ->where('status', 'cancelled')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Return all payment documents for read-only compatibility callers.
     * Automatic ledger repair must use activePayments() explicitly.
     */
    public function payments(array $filters = []): Collection
    {
        return $this->paymentQuery($filters)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    private function paymentQuery(array $filters = []): Builder
    {
        return PaysheetPayment::query()
            ->with(['employee:id,code,name,branch_id', 'paysheet:id,code,status', 'payslip:id,code'])
            ->when($filters['employee-code'] ?? null, fn (Builder $query, string $code) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('code', $code)))
            ->when($filters['employee'] ?? null, function (Builder $query, $employee) {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('id', $employee)
                    ->orWhere('code', $employee));
            })
            ->when($filters['branch'] ?? null, fn (Builder $query, $branch) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('branch_id', $branch)));
    }

    public function payslipSettlement(Payslip $slip): array
    {
        $paid = (int) $slip->payments()->where('status', 'active')->sum('amount');
        $settled = $paid + (int) $slip->applied_advance;
        $remaining = max((int) $slip->total_salary - $settled, 0);

        return [
            'expected_paid_amount' => $paid,
            'actual_paid_amount' => (int) $slip->paid_amount,
            'expected_remaining' => $remaining,
            'actual_remaining' => (int) $slip->remaining,
            'paid_matches' => (int) $slip->paid_amount === $paid,
            'remaining_matches' => (int) $slip->remaining === $remaining,
        ];
    }

    public function paysheetSettlement(Paysheet $sheet): array
    {
        $totals = $sheet->payslips()
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(remaining), 0) as remaining')
            ->first();
        $paid = (int) ($totals?->paid ?? 0);
        $remaining = (int) ($totals?->remaining ?? 0);

        return [
            'expected_total_paid' => $paid,
            'actual_total_paid' => (int) $sheet->total_paid,
            'expected_total_remaining' => $remaining,
            'actual_total_remaining' => (int) $sheet->total_remaining,
            'paid_matches' => (int) $sheet->total_paid === $paid,
            'remaining_matches' => (int) $sheet->total_remaining === $remaining,
        ];
    }

    public function accrualIdempotencyKey(Payslip $slip): string
    {
        return "payroll_accrual:{$slip->id}";
    }

    public function paymentIdempotencyKey(PaysheetPayment $payment): string
    {
        return "salary_payment:{$payment->id}";
    }
}
