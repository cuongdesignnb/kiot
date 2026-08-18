<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Services\Debt\PartnerDebtRoleResolver;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CustomerPaymentService
{
    public const CANCELLED = 'cancelled';

    public const ALREADY_CANCELLED = 'already_cancelled';

    public const SOURCE_DOCUMENT_REQUIRED = 'source_document_required';

    public function __construct(private readonly PartnerDebtMutationCoordinator $coordinator) {}

    public function collect(
        Customer $customer,
        float $paymentAmount,
        string $mode = 'auto',
        array $requestedAllocations = [],
        ?string $note = null,
        Carbon|string|null $paidAt = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($paymentAmount <= 0) {
            throw ValidationException::withMessages(['amount' => 'So tien thanh toan phai lon hon 0.']);
        }

        $paymentTime = $this->parsePaymentTime($paidAt);

        $hashPaymentTime = $paidAt === null || (is_string($paidAt) && trim($paidAt) === '')
            ? null
            : $paymentTime->toIso8601String();
        $payloadHash = hash('sha256', json_encode([
            'customer_id' => (int) $customer->id,
            'amount' => $paymentAmount,
            'mode' => $mode,
            'allocations' => $requestedAllocations,
            'note' => $note,
            'paid_at' => $hashPaymentTime,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $this->coordinator->execute(
            (int) $customer->id,
            'customer_payment_collect',
            $payloadHash,
            function (Customer $lockedCustomer) use (
                $customer,
                $paymentAmount,
                $mode,
                $requestedAllocations,
                $note,
                $paymentTime,
            ) {
                if (! (bool) $lockedCustomer->is_customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                    ]);
                }
                app(PartnerTransactionGuard::class)->assertCanTransact((int) $customer->id, 'customer_id');
                $debtBefore = (float) $lockedCustomer->debt_amount;
                $allocations = $mode === 'manual'
                    ? $this->resolveManualAllocations($lockedCustomer, $paymentAmount, $requestedAllocations, $paymentTime)
                    : $this->resolveAutomaticAllocations($lockedCustomer, $paymentAmount, $paymentTime);
                $allocatedAmount = (float) collect($allocations)->sum('amount');
                $unallocatedAmount = max(0.0, $paymentAmount - $allocatedAmount);

                $cashFlow = CashFlow::create([
                    'code' => 'PT'.date('ymdHis').random_int(10, 99),
                    'type' => 'receipt',
                    'amount' => $paymentAmount,
                    'time' => $paymentTime,
                    'category' => 'Thu no khach hang',
                    'target_type' => 'Khách hàng',
                    'target_id' => $lockedCustomer->id,
                    'target_name' => $lockedCustomer->name,
                    'reference_type' => 'DebtPayment',
                    'reference_code' => null,
                    'description' => $note ?: 'Thu no khach hang '.$lockedCustomer->name,
                    'status' => 'active',
                ]);
                $this->coordinator->checkpoint('document');

                $allocationCodes = [];
                foreach ($allocations as $allocation) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($allocation['invoice_id']);
                    $invoice->increment('customer_paid', $allocation['amount']);
                    CustomerPaymentAllocation::create([
                        'cash_flow_id' => $cashFlow->id,
                        'customer_id' => $lockedCustomer->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $allocation['amount'],
                    ]);
                    $allocationCodes[] = $invoice->code.':'.number_format($allocation['amount'], 2, '.', '');
                }
                $this->coordinator->checkpoint('evidence');

                $cashFlow->reference_code = implode(';', $allocationCodes);
                $cashFlow->save();

                app(CustomerDebtService::class)->recordPayment(
                    $lockedCustomer->id,
                    $paymentAmount,
                    null,
                    $note ?: "Thu no khach hang {$lockedCustomer->name}",
                    ['ref_code' => $cashFlow->code]
                );
                $this->coordinator->checkpoint('projection');

                $debtAfter = (float) $lockedCustomer->fresh()->debt_amount;

                return [
                    'payment_amount' => $paymentAmount,
                    'allocated_amount' => $allocatedAmount,
                    'unallocated_amount' => $unallocatedAmount,
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'is_overpayment' => $unallocatedAmount > 0.0,
                    'overpayment_amount' => $unallocatedAmount,
                    'cash_flow_id' => $cashFlow->id,
                    'cash_flow_code' => $cashFlow->code,
                ];
            },
            $idempotencyKey,
        );
    }

    public function cancel(
        CashFlow $cashFlow,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): string {
        $normalizedReason = trim((string) ($reason ?? ''));
        if (mb_strlen($normalizedReason) < 5) {
            $normalizedReason = 'Hủy phiếu thu '.$cashFlow->code;
        }

        return app(CashFlowCancellationService::class)->cancel(
            $cashFlow,
            $normalizedReason,
            $idempotencyKey,
        );
    }

    public function isFinanciallyLinked(CashFlow $cashFlow): bool
    {
        if ($cashFlow->target_id && (in_array((string) $cashFlow->target_type, PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES, true)
            || in_array((string) $cashFlow->target_type, PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES, true))) {
            return true;
        }

        return in_array($cashFlow->reference_type, [
            'DebtPayment',
            'Invoice',
            'Order',
            'OrderReturn',
            'Purchase',
            'PurchaseReturn',
            'SupplierPayment',
        ], true);
    }

    private function resolveAutomaticAllocations(
        Customer $customer,
        float $paymentAmount,
        Carbon $paymentTime,
    ): array {
        $remaining = $paymentAmount;
        $allocations = [];
        $invoices = app(CustomerReceivableInvoiceService::class)
            ->query($customer)
            ->whereRaw('COALESCE(transaction_date, created_at) <= ?', [$paymentTime->format('Y-m-d H:i:s')])
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining < 0.01) {
                break;
            }

            $invoiceRemaining = app(CustomerReceivableInvoiceService::class)->remaining($invoice);
            $allocated = min($remaining, $invoiceRemaining);
            if ($allocated < 0.01) {
                continue;
            }
            $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $allocated];
            $remaining -= $allocated;
        }

        return $allocations;
    }

    private function resolveManualAllocations(
        Customer $customer,
        float $paymentAmount,
        array $requestedAllocations,
        Carbon $paymentTime,
    ): array {
        $allocations = [];
        $allocatedTotal = 0.0;
        $seenInvoiceIds = [];

        foreach ($requestedAllocations as $requested) {
            $invoiceId = (int) ($requested['invoice_id'] ?? 0);
            $amount = (float) ($requested['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'So tien phan bo phai lon hon 0.',
                ]);
            }
            if (isset($seenInvoiceIds[$invoiceId])) {
                throw ValidationException::withMessages([
                    'allocations' => 'Moi hoa don chi duoc xuat hien mot lan trong danh sach phan bo.',
                ]);
            }
            $seenInvoiceIds[$invoiceId] = true;

            $invoice = app(CustomerReceivableInvoiceService::class)->query($customer)
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();
            if (! $invoice) {
                throw ValidationException::withMessages([
                    'allocations' => 'Hoa don phan bo khong hop le hoac khong con no.',
                ]);
            }

            $invoiceTime = $invoice->transaction_date ?? $invoice->created_at;
            if ($invoiceTime && Carbon::parse($invoiceTime)->greaterThan($paymentTime)) {
                throw ValidationException::withMessages([
                    'allocations' => "Không thể phân bổ thanh toán cho hóa đơn {$invoice->code} phát sinh sau ngày thanh toán.",
                ]);
            }

            $invoiceRemaining = app(CustomerReceivableInvoiceService::class)->remaining($invoice);
            if ($amount > $invoiceRemaining + 0.01) {
                throw ValidationException::withMessages([
                    'allocations' => "So phan bo cho hoa don {$invoice->code} vuot so con phai thu.",
                ]);
            }
            $allocatedTotal += $amount;
            if ($allocatedTotal > $paymentAmount + 0.01) {
                throw ValidationException::withMessages([
                    'allocations' => 'Tong phan bo khong duoc vuot so tien thuc nhan.',
                ]);
            }
            $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $amount];
        }

        return $allocations;
    }

    private function parsePaymentTime(Carbon|string|null $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if ($value === null || trim($value) === '') {
            return now();
        }

        $value = trim($value);
        foreach (['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $value);
                if ($parsed !== false && $parsed->format($format) === $value) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // Try the next explicit representation.
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'date' => 'Ngày thanh toán không hợp lệ. Vui lòng nhập dd/MM/yyyy HH:mm.',
            ]);
        }
    }
}
