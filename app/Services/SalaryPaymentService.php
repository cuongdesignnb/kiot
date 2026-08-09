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
        return $this->payAllocations([
            [
                'paysheet_id' => $paysheet->id,
                'items' => $items,
            ],
        ], $meta, $idempotencyKey);
    }

    /**
     * Persist one atomic payment request using one deterministic lock order.
     *
     * Every caller resolves its documents before entering this method. This
     * method then locks all paysheets ASC, all payslips ASC, and all employees
     * ASC before validating remaining amounts or writing any document.
     */
    public function payAllocations(array $allocations, array $meta, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($allocations, $meta, $idempotencyKey) {
            $normalized = collect($allocations)->map(function (array $allocation): array {
                $paysheetId = (int) ($allocation['paysheet_id'] ?? 0);
                $items = array_values($allocation['items'] ?? []);
                if ($paysheetId <= 0 || $items === []) {
                    throw ValidationException::withMessages([
                        'payments' => 'Phải chọn ít nhất một phiếu lương để thanh toán.',
                    ]);
                }

                return [
                    'paysheet_id' => $paysheetId,
                    'items' => $items,
                ];
            })->values()->all();

            if ($normalized === []) {
                throw ValidationException::withMessages([
                    'payments' => 'Phải chọn ít nhất một phiếu lương để thanh toán.',
                ]);
            }

            $allItems = collect($normalized)->flatMap(fn (array $allocation) => $allocation['items'])->values();
            $payslipIds = $allItems->pluck('payslip_id')->map(fn ($id) => (int) $id)->values();
            if ($payslipIds->contains(fn (int $id) => $id <= 0)
                || $payslipIds->count() !== $payslipIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'payments' => 'Mỗi phiếu lương chỉ được xuất hiện một lần trong một lần thanh toán.',
                ]);
            }

            $itemTotal = (int) $allItems->sum(fn (array $item) => (int) ($item['amount'] ?? 0));
            $requestedAmount = array_key_exists('amount', $meta) && $meta['amount'] !== null
                ? (int) $meta['amount']
                : null;
            if ($itemTotal <= 0 || ($requestedAmount !== null && $requestedAmount !== $itemTotal)) {
                throw ValidationException::withMessages([
                    'amount' => 'Tổng số tiền thanh toán phải khớp với số tiền phân bổ cho các phiếu lương.',
                ]);
            }

            $sheetIds = collect($normalized)
                ->pluck('paysheet_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $slipIds = $payslipIds->unique()->sort()->values()->all();

            $sheets = Paysheet::query()
                ->whereIn('id', $sheetIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($sheets->count() !== count($sheetIds)) {
                throw ValidationException::withMessages([
                    'paysheet' => 'Không tìm thấy bảng lương cần thanh toán.',
                ]);
            }

            $slips = Payslip::query()
                ->whereIn('id', $slipIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($slips->count() !== count($slipIds)) {
                throw ValidationException::withMessages([
                    'payments' => 'Không tìm thấy phiếu lương cần thanh toán.',
                ]);
            }

            $employeeIds = $slips->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $employees = Employee::query()
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($employees->count() !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'payments' => 'Không tìm thấy nhân viên của phiếu lương.',
                ]);
            }

            foreach ($normalized as $allocation) {
                $sheet = $sheets->get($allocation['paysheet_id']);
                if ($sheet->status !== 'locked') {
                    throw ValidationException::withMessages([
                        'paysheet' => 'Chỉ bảng lương đã chốt mới được thanh toán.',
                    ]);
                }

                foreach ($allocation['items'] as $item) {
                    $slip = $slips->get((int) $item['payslip_id']);
                    if (! $slip || (int) $slip->paysheet_id !== (int) $sheet->id) {
                        throw ValidationException::withMessages([
                            'payments' => 'Phiếu lương không thuộc bảng lương đang thanh toán.',
                        ]);
                    }
                    if ((int) ($item['amount'] ?? 0) <= 0) {
                        throw ValidationException::withMessages([
                            "payments.{$slip->id}.amount" => 'Số tiền thanh toán phải lớn hơn 0.',
                        ]);
                    }
                }
            }

            $createdBySheet = [];
            foreach ($normalized as $allocation) {
                $sheet = $sheets->get($allocation['paysheet_id']);
                foreach ($allocation['items'] as $item) {
                    $slip = $slips->get((int) $item['payslip_id']);
                    $amount = (int) $item['amount'];
                    $paymentKey = "{$idempotencyKey}:{$slip->id}";
                    $existing = PaysheetPayment::query()
                        ->where('idempotency_key', $paymentKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        if ((int) $existing->amount !== $amount || (int) $existing->payslip_id !== (int) $slip->id) {
                            throw ValidationException::withMessages([
                                "payments.{$slip->id}.amount" => 'Idempotency-Key đã được dùng cho một số tiền khác.',
                            ]);
                        }
                        $this->cashFlows->ensureForPayment($existing);
                        $createdBySheet[$sheet->id][] = $existing->fresh('cashFlow');

                        continue;
                    }

                    $remaining = $this->remainingFor($slip);
                    if ($amount > $remaining) {
                        throw ValidationException::withMessages([
                            "payments.{$slip->id}.amount" => 'Số tiền thanh toán vượt quá số còn phải trả của phiếu lương.',
                        ]);
                    }

                    $employee = $employees->get((int) $slip->employee_id);
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
                    $createdBySheet[$sheet->id][] = $payment->fresh('cashFlow');
                }
            }

            foreach ($createdBySheet as $sheetId => $payments) {
                $sheet = $sheets->get((int) $sheetId);
                $sheet->recalculateTotals();
                ActivityLog::log('salary_payment_create', "Thanh toán bảng lương {$sheet->code}", $sheet, [
                    'payment_ids' => collect($payments)->pluck('id')->all(),
                ]);
            }

            return collect($createdBySheet)->flatten(1)->values()->all();
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

    private function remainingFor(Payslip $slip): int
    {
        $paid = (int) PaysheetPayment::query()
            ->where('payslip_id', $slip->id)
            ->where('status', 'active')
            ->sum('amount');

        return max((int) $slip->total_salary - $paid - (int) $slip->applied_advance, 0);
    }
}
