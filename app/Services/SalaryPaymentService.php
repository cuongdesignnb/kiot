<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryPaymentService
{
    public function __construct(private EmployeeSalaryLedgerService $ledger) {}

    public function pay(Paysheet $paysheet, array $items, array $meta, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($paysheet, $items, $meta, $idempotencyKey) {
            $sheet = Paysheet::query()->lockForUpdate()->findOrFail($paysheet->id);
            if ($sheet->status !== 'locked') {
                throw ValidationException::withMessages(['paysheet' => 'Chỉ bảng lương đã chốt mới được thanh toán.']);
            }

            $created = [];
            foreach ($items as $item) {
                $slip = Payslip::where('paysheet_id', $sheet->id)
                    ->lockForUpdate()
                    ->findOrFail($item['payslip_id']);
                $paymentKey = "{$idempotencyKey}:{$slip->id}";
                $existing = PaysheetPayment::where('idempotency_key', $paymentKey)->first();
                if ($existing) {
                    $created[] = $existing;

                    continue;
                }

                $this->syncSlip($slip);
                $amount = (int) $item['amount'];
                if ($slip->payment_status === 'paid' || $amount > (int) $slip->remaining) {
                    throw ValidationException::withMessages([
                        "payments.{$slip->id}.amount" => 'Số tiền trả vượt số còn phải trả.',
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

                $cashFlow = CashFlow::create([
                    'code' => "PCPL{$payment->id}",
                    'type' => 'payment',
                    'amount' => $amount,
                    'time' => $meta['payment_date'],
                    'branch_id' => $sheet->branch_id,
                    'category' => 'Chi lương nhân viên',
                    'target_type' => 'employee',
                    'target_id' => $employee->id,
                    'target_name' => $employee->name,
                    'reference_type' => 'PaysheetPayment',
                    'reference_code' => $payment->code,
                    'payment_method' => $meta['payment_method'],
                    'description' => $meta['note'] ?? "Thanh toán {$slip->code}",
                    'status' => 'active',
                ]);
                $payment->update(['cash_flow_id' => $cashFlow->id]);

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
            ActivityLog::log('salary_payment_create', "Thanh toán bảng lương {$sheet->code}", $sheet, [
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
            $this->syncSlip($slip);
            $slip->paysheet->recalculateTotals();

            ActivityLog::log('salary_payment_cancel', "Hủy thanh toán {$locked->code}", $locked, ['reason' => $reason]);

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
}
