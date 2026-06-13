<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPaymentDiscountAllocation;
use App\Models\Invoice;
use App\Support\Status\BusinessStatus;
use Illuminate\Database\Eloquent\Builder;

class CustomerReceivableInvoiceService
{
    public function query(Customer $customer): Builder
    {
        $query = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereRaw('COALESCE(total, 0) > COALESCE(customer_paid, 0)')
            ->addSelect([
                'payment_discount_allocated' => CustomerPaymentDiscountAllocation::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('invoice_id', 'invoices.id')
                    ->whereHas('discount', fn (Builder $discountQuery) => $discountQuery->where('status', 'active')),
            ])
            ->orderByRaw('COALESCE(transaction_date, created_at) ASC')
            ->orderBy('id');

        BusinessStatus::scopeNotCancelled($query, 'status');

        return $query;
    }

    public function remaining(Invoice $invoice): float
    {
        return max(
            0.0,
            (float) $invoice->total
                - (float) $invoice->customer_paid
                - (float) ($invoice->payment_discount_allocated ?? 0)
        );
    }

    public function summaries(Customer $customer): array
    {
        return $this->query($customer)
            ->get()
            ->map(function (Invoice $invoice) {
                return [
                    'id' => $invoice->id,
                    'code' => $invoice->code,
                    'created_at' => $invoice->created_at,
                    'total' => (float) $invoice->total,
                    'customer_paid' => (float) $invoice->customer_paid,
                    'discount_allocated' => (float) ($invoice->payment_discount_allocated ?? 0),
                    'remaining' => $this->remaining($invoice),
                    'source' => 'direct_invoice',
                ];
            })
            ->filter(fn (array $invoice) => $invoice['remaining'] > 0)
            ->values()
            ->all();
    }
}
