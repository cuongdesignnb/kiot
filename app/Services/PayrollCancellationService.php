<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\SalaryAdvanceApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCancellationService
{
    public function preview(Paysheet $sheet): array
    {
        $sheet = $sheet->fresh();
        $payments = $sheet->payments()->where('status', 'active')->orderBy('id')->get();
        $slips = $sheet->payslips()->orderBy('id')->get();

        return [
            'status' => $sheet->status,
            'payments' => $payments->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'amount' => (int) $p->amount])->all(),
            'payment_total' => (int) $payments->sum('amount'),
            'confirmation_hash' => hash('sha256', json_encode([$sheet->getRawOriginal(), $slips->toArray(), $payments->toArray()])),
        ];
    }

    public function cancel(Paysheet $sheet, string $reason, $eventAt, ?string $hash = null): array
    {
        return DB::transaction(function () use ($sheet, $reason, $eventAt, $hash) {
            $sheet = Paysheet::lockForUpdate()->findOrFail($sheet->id);
            if ($sheet->status === 'cancelled') {
                return ['paysheet' => $sheet, 'reversed_entries_count' => 0, 'mode' => 'replay'];
            }
            $slips = $sheet->payslips()->orderBy('id')->lockForUpdate()->get();
            $preview = $this->preview($sheet);
            if ($hash !== null && ! hash_equals($preview['confirmation_hash'], $hash)) {
                throw ValidationException::withMessages(['confirmation_hash' => 'Dữ liệu đã thay đổi. Mở lại xác nhận hủy.']);
            }
            $payments = $sheet->payments()->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            if (! in_array($sheet->status, ['draft', 'calculated', 'locked'], true)) {
                throw ValidationException::withMessages(['status' => 'Trạng thái bảng lương không hỗ trợ hủy.']);
            }
            if ($sheet->status !== 'locked') {
                if ($payments->isNotEmpty() || $slips->contains(fn ($s) => $s->paid_amount != 0 || $s->applied_advance != 0)
                    || EmployeeSalaryLedgerEntry::where('paysheet_id', $sheet->id)->exists()
                    || SalaryAdvanceApplication::where('paysheet_id', $sheet->id)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['ledger' => 'Bảng tạm tính có dữ liệu thanh toán hoặc ghi sổ bất thường; cần audit trước khi hủy.']);
                }
                $sheet->payslips()->update(['remaining' => 0, 'payment_status' => 'unpaid']);
                $sheet->update(['status' => 'cancelled', 'needs_recalc' => false]);
                $sheet->recalculateTotals();
                $result = ['paysheet' => $sheet->fresh('payslips'), 'reversed_entries_count' => 0, 'mode' => 'unposted'];
            } else {
                foreach ($slips as $slip) {
                    if ((int) $slip->paid_amount !== (int) $payments->where('payslip_id', $slip->id)->sum('amount')) {
                        throw ValidationException::withMessages(['payments' => 'Số đã trả không khớp phiếu thanh toán; cần audit trước khi hủy.']);
                    }
                }
                $allAccruals = EmployeeSalaryLedgerEntry::where('paysheet_id', $sheet->id)
                    ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)->where('is_effective', true)->get();
                if ($allAccruals->isNotEmpty()) {
                    foreach ($slips as $slip) {
                        $entries = $allAccruals->where('payslip_id', $slip->id);
                        if ($entries->count() !== 1 || $entries->first()->amount != $slip->total_salary
                            || $entries->first()->employee_id != $slip->employee_id || $entries->first()->reversalEntries()->exists()) {
                            throw ValidationException::withMessages(['ledger' => 'Ghi nhận lương không đầy đủ hoặc đã đảo; cần audit trước khi hủy.']);
                        }
                    }
                    if ($allAccruals->count() !== $slips->count()) {
                        throw ValidationException::withMessages(['ledger' => 'Có dòng ghi sổ ngoài danh sách phiếu lương.']);
                    }
                } elseif ($slips->contains(fn ($s) => $s->applied_advance != 0)
                    || SalaryAdvanceApplication::where('paysheet_id', $sheet->id)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['ledger' => 'Bảng cũ thiếu ghi sổ nhưng đã cấn tạm ứng; cần audit.']);
                }
                if ($payments->isNotEmpty()) {
                    abort_unless(auth()->user()?->hasPermission('payroll.pay.cancel'), 403);
                    if ($hash === null) {
                        throw ValidationException::withMessages(['payments' => 'Cần xem trước và xác nhận các phiếu thanh toán sẽ hủy.']);
                    }
                    foreach ($slips as $slip) {
                        $accruals = EmployeeSalaryLedgerEntry::where('payslip_id', $slip->id)->where('paysheet_id', $sheet->id)
                            ->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)->where('is_effective', true)->get();
                        if ($accruals->count() !== 1 || $accruals->first()->amount != $slip->total_salary) {
                            throw ValidationException::withMessages(['ledger' => 'Thiếu hoặc lệch ghi nhận lương; cần audit trước khi hủy thanh toán kèm bảng.']);
                        }
                    }
                    foreach ($payments as $payment) {
                        $slip = $slips->firstWhere('id', $payment->payslip_id);
                        $entries = EmployeeSalaryLedgerEntry::where('reference_type', 'paysheet_payment')->where('reference_id', $payment->id)
                            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)->get();
                        $flows = app(PayrollPaymentCashFlowService::class)->cashFlowsForPayment($payment)->get();
                        if (! $slip || $payment->amount <= 0 || $slip->employee_id != $payment->employee_id || $entries->count() !== 1
                            || $entries->first()->amount != -$payment->amount || $entries->first()->paysheet_id != $sheet->id
                            || $entries->first()->employee_id != $payment->employee_id || $entries->first()->payslip_id != $slip->id
                            || ! $entries->first()->is_effective || $entries->first()->reversalEntries()->exists()
                            || $flows->count() !== 1 || $flows->first()->reference_type !== PayrollPaymentCashFlowService::REFERENCE_TYPE
                            || $flows->first()->type !== 'payment' || $flows->first()->deleted_at !== null || $flows->first()->status === 'cancelled'
                            || $flows->first()->reference_code !== $payment->code || $flows->first()->amount != $payment->amount) {
                            throw ValidationException::withMessages(['payments' => 'Liên kết phiếu thanh toán không khớp; cần audit, chưa hủy dữ liệu.']);
                        }
                        app(SalaryPaymentService::class)->cancel($payment, $reason, $eventAt);
                    }
                }
                $result = app(PayrollPostingService::class)->cancel($sheet, $reason, $eventAt);
            }
            ActivityLog::log('paysheet_cancel_reviewed', "Hủy có xác nhận {$sheet->code}", $sheet, [
                'reason' => $reason, 'before' => $preview, 'payslips_before' => $slips->toArray(),
                'cancelled_payment_ids' => $payments->pluck('id')->all(), 'mode' => $result['mode'],
            ]);

            return $result;
        });
    }
}
