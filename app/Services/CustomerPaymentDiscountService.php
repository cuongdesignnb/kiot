<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPaymentDiscount;
use App\Models\CustomerPaymentDiscountAllocation;
use App\Models\Invoice;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPaymentDiscountService
{
    public function __construct(private readonly PartnerDebtMutationCoordinator $coordinator) {}

    public function getInvoiceDiscountAllocatedAmount(int $invoiceId): float
    {
        return (float) CustomerPaymentDiscountAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->whereHas('discount', fn ($q) => $q->where('status', 'active'))
            ->sum('amount');
    }

    public function getInvoiceRemainingReceivable(Invoice $invoice): float
    {
        if ($invoice->status === 'Đã hủy') {
            return 0.0;
        }

        $allocated = $this->getInvoiceDiscountAllocatedAmount($invoice->id);

        return max(0.0, (float) $invoice->total - (float) $invoice->customer_paid - $allocated);
    }

    public function getCustomerReceivableInvoices(Customer $customer): array
    {
        return app(CustomerReceivableInvoiceService::class)->summaries($customer);
    }

    public function getDiscountableInvoices(Customer $customer): array
    {
        return $this->getCustomerReceivableInvoices($customer);
    }

    public function create(Customer $customer, array $payload, ?string $idempotencyKey = null): CustomerPaymentDiscount
    {
        $mutation = function (Customer $customer) use ($payload): CustomerPaymentDiscount {
            if (! (bool) $customer->is_customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                ]);
            }
            app(PartnerTransactionGuard::class)->assertCanTransact(
                (int) $customer->id,
                'customer_id'
            );

            $currentDebt = (float) $customer->debt_amount;
            if ($currentDebt <= 0) {
                throw new \InvalidArgumentException('Khách hàng không còn nợ phải thu, không thể tạo chiết khấu.');
            }

            $amount = (float) $payload['amount'];
            if ($amount <= 0 || $amount > $currentDebt) {
                throw new \InvalidArgumentException('Số tiền chiết khấu phải lớn hơn 0 và nhỏ hơn hoặc bằng nợ hiện tại.');
            }

            $allocate = (bool) ($payload['allocate_to_invoices'] ?? true);
            $allocations = $payload['allocations'] ?? [];

            if ($allocate) {
                if (empty($allocations)) {
                    throw new \InvalidArgumentException('Yêu cầu danh sách phân bổ hóa đơn.');
                }

                $totalAlloc = 0.0;
                $receivableInvoices = collect($this->getCustomerReceivableInvoices($customer))->keyBy('id');

                foreach ($allocations as $alloc) {
                    $resolved = $receivableInvoices->get((int) $alloc['invoice_id']);
                    if (! $resolved) {
                        throw new \InvalidArgumentException("Hóa đơn ID {$alloc['invoice_id']} không hợp lệ hoặc đã hủy.");
                    }

                    $invoice = Invoice::find($resolved['id']);
                    if (! $invoice) {
                        throw new \InvalidArgumentException("Hóa đơn ID {$alloc['invoice_id']} không hợp lệ hoặc đã hủy.");
                    }

                    $remaining = $resolved['remaining'];
                    $allocAmount = (float) $alloc['amount'];

                    if ($allocAmount <= 0) {
                        continue;
                    }

                    if ($allocAmount > $remaining + 0.01) {
                        throw new \InvalidArgumentException("Số tiền phân bổ cho hóa đơn {$invoice->code} vượt quá số tiền còn phải thu ({$remaining}).");
                    }

                    $totalAlloc += $allocAmount;
                }

                if (abs($totalAlloc - $amount) > 0.01) {
                    throw new \InvalidArgumentException('Tổng số tiền phân bổ phải bằng tổng số tiền chiết khấu.');
                }
            }

            // Generate code
            $code = 'CKTT'.date('ymdHis').rand(10, 99);
            while (CustomerPaymentDiscount::where('code', $code)->exists()) {
                $code = 'CKTT'.date('ymdHis').rand(10, 99);
            }

            $discountAt = ! empty($payload['discount_at']) ? Carbon::parse($payload['discount_at']) : now();

            $discount = CustomerPaymentDiscount::create([
                'code' => $code,
                'customer_id' => $customer->id,
                'amount' => $amount,
                'discount_at' => $discountAt,
                'performed_by' => $payload['performed_by'] ?? auth()->id(),
                'created_by' => auth()->id(),
                'allocate_to_invoices' => $allocate,
                'status' => 'active',
                'note' => $payload['note'] ?? null,
            ]);

            if ($allocate) {
                foreach ($allocations as $alloc) {
                    $allocAmount = (float) $alloc['amount'];
                    if ($allocAmount <= 0) {
                        continue;
                    }

                    CustomerPaymentDiscountAllocation::create([
                        'customer_payment_discount_id' => $discount->id,
                        'customer_id' => $customer->id,
                        'invoice_id' => $alloc['invoice_id'],
                        'amount' => $allocAmount,
                    ]);
                }
            }

            // Record into ledger (as signed negative adjustment)
            app(CustomerDebtService::class)->recordAdjustment(
                $customer->id,
                -$amount,
                'Chiết khấu thanh toán '.$discount->code.($discount->note ? ' - '.$discount->note : ''),
                ['ref_code' => $discount->code]
            );

            return $discount;
        };

        return $this->coordinator->execute(
            (int) $customer->id,
            'customer_payment_discount_create',
            hash('sha256', json_encode([
                'customer_id' => (int) $customer->id,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            fn (Customer $lockedCustomer): CustomerPaymentDiscount => DB::transaction(
                fn (): CustomerPaymentDiscount => $mutation($lockedCustomer),
            ),
            $idempotencyKey,
        );
    }

    public function cancel(
        CustomerPaymentDiscount $discount,
        ?string $reason,
        ?string $idempotencyKey = null,
    ): void {
        $this->coordinator->execute(
            (int) $discount->customer_id,
            'customer_payment_discount_cancel',
            hash('sha256', json_encode([
                'discount_id' => (int) $discount->id,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE)),
            function (Customer $lockedCustomer) use ($discount, $reason): void {
                if (! (bool) $lockedCustomer->is_customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                    ]);
                }

                DB::transaction(function () use ($discount, $reason, $lockedCustomer): void {
                    $discount = CustomerPaymentDiscount::lockForUpdate()->findOrFail($discount->id);
                    if ($discount->isCancelled()) {
                        throw new \InvalidArgumentException('Phiếu chiết khấu này đã được hủy trước đó.');
                    }

                    $discount->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancelled_by' => auth()->id(),
                        'cancel_reason' => $reason,
                    ]);

                    // Revert into ledger (as signed positive adjustment)
                    app(CustomerDebtService::class)->recordAdjustment(
                        $lockedCustomer->id,
                        (float) $discount->amount,
                        'Hủy chiết khấu thanh toán '.$discount->code.($reason ? ' - '.$reason : ''),
                        ['ref_code' => $discount->code]
                    );
                });
            },
            $idempotencyKey,
        );
    }
}
