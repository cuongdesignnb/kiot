<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\CustomerPaymentDiscount;
use App\Models\CustomerPaymentDiscountAllocation;
use App\Models\CustomerDebt;
use App\Services\CustomerDebtService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CustomerPaymentDiscountService
{
    public function getInvoiceDiscountAllocatedAmount(int $invoiceId): float
    {
        return (float) CustomerPaymentDiscountAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->whereHas('discount', fn($q) => $q->where('status', 'active'))
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
        // Source 1: Direct invoices
        $directInvoices = Invoice::where('customer_id', $customer->id)
            ->where('status', '!=', 'Đã hủy')
            ->get();

        // Source 2: Ledger invoices
        $ledgerInvoiceCodes = CustomerDebt::where('customer_id', $customer->id)
            ->where('type', 'sale')
            ->where('amount', '>', 0)
            ->whereNotNull('ref_code')
            ->where('ref_code', 'like', 'HD%')
            ->pluck('ref_code')
            ->unique()
            ->all();

        $ledgerInvoices = [];
        if (!empty($ledgerInvoiceCodes)) {
            $ledgerInvoices = Invoice::whereIn('code', $ledgerInvoiceCodes)
                ->where('status', '!=', 'Đã hủy')
                ->get();
        }

        $merged = collect();
        $addedIds = [];

        foreach ($directInvoices as $invoice) {
            $remaining = $this->getInvoiceRemainingReceivable($invoice);
            if ($remaining > 0) {
                $merged->push([
                    'invoice' => $invoice,
                    'source' => 'direct_invoice',
                    'remaining' => $remaining,
                ]);
                $addedIds[$invoice->id] = true;
            }
        }

        foreach ($ledgerInvoices as $invoice) {
            if (isset($addedIds[$invoice->id])) {
                continue;
            }
            $remaining = $this->getInvoiceRemainingReceivable($invoice);
            if ($remaining > 0) {
                $merged->push([
                    'invoice' => $invoice,
                    'source' => 'ledger_invoice',
                    'remaining' => $remaining,
                ]);
                $addedIds[$invoice->id] = true;
            }
        }

        // Sort by created_at ascending (oldest first)
        $sorted = $merged->sortBy(function ($item) {
            return $item['invoice']->created_at;
        })->values();

        $result = [];
        foreach ($sorted as $item) {
            $inv = $item['invoice'];
            $allocated = $this->getInvoiceDiscountAllocatedAmount($inv->id);
            $result[] = [
                'id' => $inv->id,
                'code' => $inv->code,
                'created_at' => $inv->created_at,
                'total' => (float) $inv->total,
                'customer_paid' => (float) $inv->customer_paid,
                'discount_allocated' => $allocated,
                'remaining' => $item['remaining'],
                'source' => $item['source'],
            ];
        }

        return $result;
    }

    public function getDiscountableInvoices(Customer $customer): array
    {
        return $this->getCustomerReceivableInvoices($customer);
    }

    public function create(Customer $customer, array $payload): CustomerPaymentDiscount
    {
        return DB::transaction(function () use ($customer, $payload) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);

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
                    if (!$resolved) {
                        throw new \InvalidArgumentException("Hóa đơn ID {$alloc['invoice_id']} không hợp lệ hoặc đã hủy.");
                    }

                    $invoice = Invoice::find($resolved['id']);
                    if (!$invoice) {
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
            $code = 'CKTT' . date('ymdHis') . rand(10, 99);
            while (CustomerPaymentDiscount::where('code', $code)->exists()) {
                $code = 'CKTT' . date('ymdHis') . rand(10, 99);
            }

            $discountAt = !empty($payload['discount_at']) ? Carbon::parse($payload['discount_at']) : now();

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
                'Chiết khấu thanh toán ' . $discount->code . ($discount->note ? ' - ' . $discount->note : ''),
                ['ref_code' => $discount->code]
            );

            return $discount;
        });
    }

    public function cancel(CustomerPaymentDiscount $discount, ?string $reason): void
    {
        DB::transaction(function () use ($discount, $reason) {
            $discount = CustomerPaymentDiscount::lockForUpdate()->findOrFail($discount->id);
            if ($discount->isCancelled()) {
                throw new \InvalidArgumentException('Phiếu chiết khấu này đã được hủy trước đó.');
            }

            $customer = Customer::lockForUpdate()->findOrFail($discount->customer_id);

            $discount->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);

            // Revert into ledger (as signed positive adjustment)
            app(CustomerDebtService::class)->recordAdjustment(
                $customer->id,
                (float) $discount->amount,
                'Hủy chiết khấu thanh toán ' . $discount->code . ($reason ? ' - ' . $reason : ''),
                ['ref_code' => $discount->code]
            );
        });
    }
}
