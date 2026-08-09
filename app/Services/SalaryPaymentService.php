<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryPaymentService
{
    public function __construct(
        private EmployeeSalaryLedgerService $ledger,
        private PayrollPaymentCashFlowService $cashFlows,
    ) {}

    public function pay(Paysheet $paysheet, array $items, array $meta, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($paysheet, $items, $meta, $idempotencyKey) {
            $sheet = Paysheet::query()->lockForUpdate()->findOrFail($paysheet->id);
            if ($sheet->status !== 'locked') {
                throw ValidationException::withMessages(['paysheet' => 'Chỉ bảng lương đã chốt mới được thanh toán.']);
            }
            if ($items === []) {
                throw ValidationException::withMessages(['payments' => 'Phải chọn ít nhất một phiếu lương để thanh toán.']);
            }

            $payslipIds = collect($items)->pluck('payslip_id')->map(fn ($id) => (int) $id);
            if ($payslipIds->count() !== $payslipIds->unique()->count()) {
                throw ValidationException::withMessages(['payments' => 'Mỗi phiếu lương chỉ được xuất hiện một lần trong một lần thanh toán.']);
            }
            $requestedAmount = array_key_exists('amount', $meta) && $meta['amount'] !== null
                ? (int) $meta['amount']
                : null;
            $itemTotal = (int) collect($items)->sum(fn ($item) => (int) ($item['amount'] ?? 0));
            if ($itemTotal <= 0 || ($requestedAmount !== null && $requestedAmount !== $itemTotal)) {
                throw ValidationException::withMessages([
                    'amount' => 'Tổng số tiền thanh toán phải khớp với số tiền phân bổ cho các phiếu lương.',
                ]);
            }

            $created = [];
            foreach ($items as $item) {
                $slip = Payslip::where('paysheet_id', $sheet->id)
                    ->lockForUpdate()
                    ->findOrFail($item['payslip_id']);
                $amount = (int) ($item['amount'] ?? 0);
                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        "payments.{$slip->id}.amount" => 'Số tiền thanh toán phải lớn hơn 0.',
                    ]);
                }
                $paymentKey = "{$idempotencyKey}:{$slip->id}";
                $existing = PaysheetPayment::where('idempotency_key', $paymentKey)->first();
                if ($existing) {
                    if ((int) $existing->amount !== $amount || (int) $existing->payslip_id !== (int) $slip->id) {
                        throw ValidationException::withMessages([
                            "payments.{$slip->id}.amount" => 'Idempotency-Key đã được dùng cho một số tiền khác.',
                        ]);
                    }
                    $this->cashFlows->ensureForPayment($existing);
                    $created[] = $existing->fresh('cashFlow');

                    continue;
                }

                $remaining = $this->remainingFor($slip);
                if ($amount > $remaining) {
                    throw ValidationException::withMessages([
                        "payments.{$slip->id}.amount" => 'Số tiền thanh toán vượt quá số còn phải trả của phiếu lương.',
                    ]);
                }

                $employee = Employee::query()->lockForUpdate()->findOrFail($slip->employee_id);
                $payment = PaysheetPayment::create([
                    'code' => null,
                    'paysheet_id' => $sheet->id,
                    'payslip_id' => $slip->id,
                    'employee_id' => $slip->employee_id,
                    'amount' => $amount,
                    'status' => 'active',
                    'method' => $meta['payment_method'],
                    'notes' => $meta['note'] ?? null,
                    'paid_at' => $meta['payment_date'],
                    'created_by' => auth()->id(),
                    'idempotency_key' => $paymentKey,
                ]);
                $payment->update([
                    'code' => 'TTPL'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                ]);

                $this->cashFlows->ensureForPayment($payment);

                $this->ledger->append($employee, [
                    'paysheet_id' => $sheet->id,
                    'payslip_id' => $slip->id,
                    'code' => $payment->code,
                    'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
                    'reference_type' => 'paysheet_payment',
                    'reference_id' => $payment->id,
                    'amount' => -$amount,
                    'event_at' => $meta['payment_date'],
                    'payment_method' => $meta['payment_method'],
                    'note' => $meta['note'] ?? null,
                    'idempotency_key' => "salary_payment:{$payment->id}",
                ]);

                $this->syncSlip($slip);
                $created[] = $payment->fresh('cashFlow');
            }

            $sheet->recalculateTotals();
            ActivityLog::log('salary_payment_create', "Thanh toan bang luong {$sheet->code}", $sheet, [
                'payment_ids' => collect($created)->pluck('id')->all(),
            ]);

            return $created;
        });
    }

    public function cancel(PaysheetPayment $payment, string $reason, $eventAt): PaysheetPayment
    {
        return DB::transaction(function () use ($payment, $reason, $eventAt) {
            $locked = PaysheetPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $slip = Payslip::query()->lockForUpdate()->findOrFail($locked->payslip_id);
            $entry = EmployeeSalaryLedgerEntry::where('reference_type', 'paysheet_payment')
                ->where('reference_id', $locked->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
                ->firstOrFail();

            $this->ledger->reverse(
                $entry,
                "H{$locked->code}",
                $eventAt,
                $reason,
                "cancel_salary_payment:{$locked->id}"
            );

            $this->cashFlows->cancelForPayment($locked, $reason);

            $locked->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            $this->syncSlip($slip);
            $slip->paysheet->recalculateTotals();

            ActivityLog::log('salary_payment_cancel', "Huy thanh toan {$locked->code}", $locked, ['reason' => $reason]);

            return $locked->fresh();
        });
    }

    public function syncSlip(Payslip $slip): void
    {
        $paid = (int) PaysheetPayment::where('payslip_id', $slip->id)
            ->where('status', 'active')
            ->sum('amount');
        $settled = $paid + (int) $slip->applied_advance;
        $remaining = max((int) $slip->total_salary - $settled, 0);
        $slip->update([
            'paid_amount' => $paid,
            'remaining' => $remaining,
            'payment_status' => $remaining === 0 ? 'paid' : ($settled > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    private function remainingFor(Payslip $slip): int
    {
        $paid = (int) PaysheetPayment::query()
            ->where('payslip_id', $slip->id)
            ->where('status', 'active')
            ->sum('amount');

        return max((int) $slip->total_salary - $paid - (int) $slip->applied_advance, 0);
    }
}
