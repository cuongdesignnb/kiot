<?php

namespace App\Services\Debt\Source;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Support\Debt\PartnerDebtDisplayBalance;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CustomerDebtDomainEventSource
{
    /**
     * Return customer-domain evidence only. Running balances and presentation
     * aliases produced by the legacy builder are deliberately discarded by
     * the canonical reducer; this source is never exposed to controllers.
     */
    public function events(Customer $customer, array $options = []): Collection
    {
        // `view=partner` was part of the retired cross-role mirror path. A
        // domain evidence source never receives a presentation selector.
        unset($options['view']);

        $payload = $this->build($customer, array_merge($options, [
            'domain_only' => true,
            'canonical' => true,
        ]));

        return collect($payload['entries'] ?? [])->values();
    }

    public function build(Customer $customer, array $options = []): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = $hasSupplierColumn && PartnerDebtDisplayBalance::isDualRole($customer);
        // This source is customer-domain evidence only. The canonical reducer
        // combines it with the supplier-domain source exactly once for
        // dual-role partners; no caller may re-enable a cross-role mirror.
        $domainOnly = true;

        $entries = collect();
        $purchases = collect();
        $workflowOffsetsForConsolidation = collect();
        $excludedLedgerEntries = [];
        $includeTechnical = (bool) ($options['include_technical'] ?? $options['audit'] ?? false);

        // 1. Invoices
        $invoices = Invoice::where('customer_id', $customer->id)
            ->get()
            ->values();

        $invoiceCodes = $invoices->pluck('code')->filter()->toArray();

        foreach ($invoices as $invoice) {
            $businessTime = $invoice->transaction_date ?: $invoice->created_at;
            $entries->push($this->createEntry([
                'id' => 'invoice-'.$invoice->id,
                'code' => $invoice->code,
                'display_type' => 'Bán hàng',
                'event_kind' => 'customer_sale',
                'domain' => 'customer',
                'source_status' => $invoice->status,
                'document_amount' => (float) $invoice->total,
                'amount' => (float) $invoice->total,
                'display_effect' => (float) $invoice->total,
                'customer_display_effect' => (float) $invoice->total,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $invoice->created_at,
                'reference_type' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_code' => $invoice->code,
                'detail_available' => true,
                'detail_modal_type' => 'invoice',
                'detail_reference_id' => $invoice->id,
                'detail_reference_code' => $invoice->code,
                'badge_label' => null,
                'badge_title' => null,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'document_first',
                'document_group_key' => $invoice->code,
                'document_group_type' => 'invoice',
                'document_group_parent_code' => $invoice->code,
                'document_group_time' => $businessTime,
                'document_group_sequence' => 10,
                'sort_group_key' => $invoice->code,
                'sort_group_time' => $businessTime,
                'sort_group_sequence' => 10,
                'debug' => [
                    'document_source' => 'invoices',
                    'invoice_total' => (float) $invoice->total,
                    'invoice_customer_paid' => (float) $invoice->customer_paid,
                    'must_display_invoice_total' => true,
                ],
            ]));

            if (BusinessStatus::isCancelled($invoice->status)) {
                $cancelledAt = $invoice->cancelled_at ?: $invoice->updated_at ?: $businessTime;
                $entries->push($this->createEntry([
                    'id' => 'invoice-cancel-'.$invoice->id,
                    'code' => 'HUY-'.$invoice->code,
                    'display_type' => 'Hủy hóa đơn',
                    'type_raw' => 'invoice_cancel_reversal',
                    'event_kind' => 'invoice_cancel_reversal',
                    'domain' => 'customer',
                    'document_amount' => (float) $invoice->total,
                    'amount' => -(float) $invoice->total,
                    'display_effect' => -(float) $invoice->total,
                    'customer_display_effect' => -(float) $invoice->total,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'reference_type' => 'Invoice',
                    'reference_id' => $invoice->id,
                    'reference_code' => $invoice->code,
                    'reversal_of' => 'customer|invoices|'.$invoice->id.'|customer_sale|receivable',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                    'document_group_key' => $invoice->code,
                    'document_group_type' => 'invoice',
                    'document_group_parent_code' => $invoice->code,
                    'document_group_time' => $businessTime,
                    'document_group_sequence' => 90,
                    'sort_group_key' => $invoice->code,
                    'sort_group_time' => $businessTime,
                    'sort_group_sequence' => 90,
                ]));

                if ((float) $invoice->customer_paid > 0.01) {
                    $entries->push($this->createEntry([
                        'id' => 'invoice-payment-cancel-'.$invoice->id,
                        'code' => 'HUY-TT-'.$invoice->code,
                        'display_type' => 'Hủy thanh toán hóa đơn',
                        'type_raw' => 'invoice_payment_cancel_reversal',
                        'event_kind' => 'invoice_payment_cancel_reversal',
                        'domain' => 'customer',
                        'document_amount' => (float) $invoice->customer_paid,
                        'amount' => (float) $invoice->customer_paid,
                        'display_effect' => +(float) $invoice->customer_paid,
                        'customer_display_effect' => +(float) $invoice->customer_paid,
                        'time' => $cancelledAt,
                        'display_time' => $cancelledAt,
                        'created_at' => $cancelledAt,
                        'reference_type' => 'Invoice',
                        'reference_id' => $invoice->id,
                        'reference_code' => $invoice->code,
                        'reversal_of' => 'customer|invoices|'.$invoice->id.'|invoice_payment|receivable',
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'source' => 'document_first',
                        'document_group_key' => $invoice->code,
                        'document_group_type' => 'invoice',
                        'document_group_parent_code' => $invoice->code,
                        'document_group_time' => $businessTime,
                        'document_group_sequence' => 91,
                        'sort_group_key' => $invoice->code,
                        'sort_group_time' => $businessTime,
                        'sort_group_sequence' => 91,
                    ]));
                }
            }
        }

        // 2. Receipt CashFlows (both linked and standalone)
        $receipts = CashFlow::active()
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->get()
            ->filter(fn (CashFlow $cashFlow) => $this->isCustomerCashFlow($cashFlow))
            ->reject(fn (CashFlow $cashFlow) => $this->isDebtOffsetEvidenceCashFlow($cashFlow))
            ->values();

        // Allocate real receipts first. Legacy DebtPayment vouchers may carry
        // multiple invoice allocations in reference_code (HD...:amount; ...).
        $receiptsByInvoice = [];
        $standaloneReceipts = [];

        foreach ($receipts as $cf) {
            $refCode = (string) $cf->reference_code;
            if ($cf->reference_type === 'Invoice' && $refCode && in_array($refCode, $invoiceCodes, true)) {
                $receiptsByInvoice[$refCode][] = [
                    'cash_flow' => $cf,
                    'amount' => (float) $cf->amount,
                    'strategy' => 'direct_invoice_reference',
                ];

                continue;
            }

            $allocated = 0.0;
            foreach ($this->legacyInvoiceAllocations($refCode) as $invoiceCode => $amount) {
                if (! in_array($invoiceCode, $invoiceCodes, true) || $amount <= 0.01) {
                    continue;
                }

                $receiptsByInvoice[$invoiceCode][] = [
                    'cash_flow' => $cf,
                    'amount' => $amount,
                    'strategy' => 'legacy_reference_allocation',
                ];
                $allocated += $amount;
            }

            $unallocated = max(0.0, (float) $cf->amount - $allocated);
            if ($unallocated > 0.01) {
                $standaloneReceipts[] = [
                    'cash_flow' => $cf,
                    'amount' => $unallocated,
                    'allocated_amount' => $allocated,
                ];
            }
        }

        // Emit linked receipts
        foreach ($receiptsByInvoice as $refCode => $allocations) {
            $invoice = $invoices->firstWhere('code', $refCode);
            $invoicePaid = $invoice ? (float) $invoice->customer_paid : 0.0;
            $receiptTotal = (float) collect($allocations)->sum('amount');
            $mismatch = abs($receiptTotal - $invoicePaid) > 0.01;

            foreach ($allocations as $index => $allocation) {
                /** @var CashFlow $cf */
                $cf = $allocation['cash_flow'];
                $allocatedAmount = (float) $allocation['amount'];
                $businessTime = $cf->time ?: $cf->created_at;
                $invoiceTime = $invoice ? ($invoice->transaction_date ?: $invoice->created_at) : ($cf->time ?: $cf->created_at);
                $entries->push($this->createEntry([
                    'id' => 'cash-flow-allocation-'.$cf->id.'-'.$refCode,
                    'code' => $cf->code,
                    'display_type' => 'Thanh toán hóa đơn',
                    'event_kind' => 'invoice_payment',
                    'domain' => 'customer',
                    'document_amount' => $allocatedAmount,
                    'amount' => $allocatedAmount,
                    'display_effect' => -$allocatedAmount,
                    'customer_display_effect' => -$allocatedAmount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => 'Invoice',
                    'reference_id' => $invoice ? $invoice->id : null,
                    'reference_code' => $refCode,
                    'parent_document_code' => $refCode,
                    'payment_for_code' => $refCode,
                    'linked_document_code' => $refCode,
                    'linked_document_label' => 'Thanh toán cho '.$refCode,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cf->id,
                    'detail_reference_code' => $cf->code,
                    'badge_label' => $mismatch ? 'Cần đối soát' : 'Thanh toán',
                    'badge_title' => $mismatch ? 'Tổng phiếu thu thật không khớp số đã thanh toán trên hóa đơn.' : null,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source_table' => 'cash_flows',
                    'source_id' => $cf->id.':'.$refCode,
                    'allocation_strategy' => $allocation['strategy'],
                    'receipt_allocation_mismatch' => $mismatch,
                    'needs_manual_review' => $mismatch,
                    'source' => 'document_first',
                    'document_group_key' => $refCode,
                    'document_group_type' => 'invoice',
                    'document_group_parent_code' => $refCode,
                    'document_group_time' => $invoiceTime,
                    'document_group_sequence' => 20 + $index,
                    'sort_group_key' => $refCode,
                    'sort_group_time' => $invoiceTime,
                    'sort_group_sequence' => 20 + $index,
                ]));
            }
        }

        // 3. Legacy fallback is permitted only when the invoice has no real
        // receipt evidence at all. A partial real receipt remains the source
        // of truth; adding a TTHD remainder would double-count it.
        foreach ($invoices as $invoice) {
            $realAllocated = (float) collect($receiptsByInvoice[$invoice->code] ?? [])->sum('amount');
            $fallbackAmount = (float) $invoice->customer_paid;
            if ($realAllocated <= 0.01 && $fallbackAmount > 0.01) {
                $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                $entries->push($this->createEntry([
                    'id' => 'invpay-fallback-'.$invoice->id,
                    'code' => 'TTHD'.preg_replace('/^HD/', '', $invoice->code),
                    'display_type' => 'Thanh toán hóa đơn',
                    'event_kind' => 'invoice_payment', // wait, must be invoice_payment for display sequencing
                    'domain' => 'customer',
                    'document_amount' => $fallbackAmount,
                    'amount' => $fallbackAmount,
                    'display_effect' => -$fallbackAmount,
                    'customer_display_effect' => -$fallbackAmount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $invoice->created_at,
                    'reference_type' => 'Invoice',
                    'reference_id' => $invoice->id,
                    'reference_code' => $invoice->code,
                    'parent_document_code' => $invoice->code,
                    'payment_for_code' => $invoice->code,
                    'linked_document_code' => $invoice->code,
                    'linked_document_label' => 'Thanh toán cho '.$invoice->code,
                    'is_virtual_fallback' => true,
                    'is_virtual_payment' => true,
                    'is_real_voucher' => false,
                    'detail_available' => false,
                    'detail_modal_type' => 'none',
                    'badge_label' => 'Tạm tính',
                    'badge_title' => 'Tạm tính từ hóa đơn — chưa tìm thấy phiếu thu thật.',
                    'source' => 'document_first',
                    'document_group_key' => $invoice->code,
                    'document_group_type' => 'invoice',
                    'document_group_parent_code' => $invoice->code,
                    'document_group_time' => $businessTime,
                    'document_group_sequence' => 20,
                    'sort_group_key' => $invoice->code,
                    'sort_group_time' => $businessTime,
                    'sort_group_sequence' => 20,
                    'fallback_for_unallocated_amount' => true,
                    'real_allocated_amount' => $realAllocated,
                ]));
            }
        }

        // 4. Standalone Receipts (including DebtAdjustment if it's type receipt)
        foreach ($standaloneReceipts as $receipt) {
            /** @var CashFlow $cf */
            $cf = $receipt['cash_flow'];
            $canonicalAmount = (float) $receipt['amount'];
            $businessTime = $cf->time ?: $cf->created_at;
            $isAdjustment = $cf->reference_type === 'DebtAdjustment';

            $entries->push($this->createEntry([
                'id' => 'cash_flow-'.$cf->id,
                'code' => $cf->code,
                'display_type' => $isAdjustment ? 'Điều chỉnh công nợ' : 'Khách thanh toán',
                'event_kind' => $isAdjustment ? 'debt_adjustment' : 'customer_payment',
                'domain' => 'customer',
                'document_amount' => $canonicalAmount,
                'amount' => $canonicalAmount,
                'display_effect' => -$canonicalAmount,
                'customer_display_effect' => -$canonicalAmount,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $cf->created_at,
                'reference_type' => $cf->reference_type ?: 'CashFlow',
                'reference_id' => $cf->id,
                'reference_code' => $cf->reference_code,
                'detail_available' => true,
                'detail_modal_type' => 'cash_flow',
                'detail_reference_id' => $cf->id,
                'detail_reference_code' => $cf->code,
                'badge_label' => $isAdjustment ? 'Điều chỉnh' : 'Thanh toán',
                'badge_title' => $cf->description ?: $cf->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'is_virtual_display_adjustment' => $isAdjustment,
                'is_debt_adjustment_cashflow' => $isAdjustment,
                'allocated_amount' => (float) $receipt['allocated_amount'],
                'source' => 'document_first',
            ]));
        }

        // 5. Payment CashFlows targeting Khách hàng (Refunds or DebtAdjustment if type payment)
        $payments = CashFlow::active()
            ->where('target_id', $customer->id)
            ->where('type', 'payment')
            ->get()
            ->filter(fn (CashFlow $cashFlow) => $this->isCustomerCashFlow($cashFlow))
            ->reject(fn (CashFlow $cashFlow) => $this->isDebtOffsetEvidenceCashFlow($cashFlow))
            ->values();

        foreach ($payments as $cf) {
            $businessTime = $cf->time ?: $cf->created_at;
            $isAdjustment = $cf->reference_type === 'DebtAdjustment';

            $entries->push($this->createEntry([
                'id' => 'cash_flow-'.$cf->id,
                'code' => $cf->code,
                'display_type' => $isAdjustment ? 'Điều chỉnh công nợ' : 'Hoàn tiền khách',
                'event_kind' => $isAdjustment ? 'debt_adjustment' : 'refund',
                'domain' => 'customer',
                'document_amount' => (float) $cf->amount,
                'amount' => (float) $cf->amount,
                'display_effect' => +(float) $cf->amount,
                'customer_display_effect' => +(float) $cf->amount,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $cf->created_at,
                'reference_type' => $cf->reference_type ?: 'CashFlow',
                'reference_id' => $cf->id,
                'reference_code' => $cf->reference_code,
                'detail_available' => true,
                'detail_modal_type' => 'cash_flow',
                'detail_reference_id' => $cf->id,
                'detail_reference_code' => $cf->code,
                'badge_label' => $isAdjustment ? 'Điều chỉnh' : 'Hoàn tiền',
                'badge_title' => $cf->description ?: $cf->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'is_virtual_display_adjustment' => $isAdjustment,
                'is_debt_adjustment_cashflow' => $isAdjustment,
                'source' => 'document_first',
            ]));
        }

        // Preserve cancelled standalone vouchers as an exact original plus
        // reversal pair. Source-document vouchers are reversed by their
        // invoice/return documents and are excluded here.
        $cancelledStandaloneCashFlows = CashFlow::withTrashed()
            ->where('target_id', $customer->id)
            ->whereIn('target_type', PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES)
            ->where(function ($query): void {
                $query->whereNull('reference_type')
                    ->orWhereIn('reference_type', ['', 'CashFlow', 'DebtPayment']);
            })
            ->get()
            ->filter(fn (CashFlow $cashFlow): bool => $cashFlow->trashed()
                || ! BusinessStatus::isValidCashFlow($cashFlow->status));
        foreach ($cancelledStandaloneCashFlows as $cashFlow) {
            $kind = $cashFlow->type === 'receipt' ? 'customer_payment' : 'refund';
            $originalDelta = $cashFlow->type === 'receipt'
                ? -(float) $cashFlow->amount
                : (float) $cashFlow->amount;
            $originalIdentity = "customer|cash_flows|{$cashFlow->id}|{$kind}|receivable";
            $originalTime = $cashFlow->time ?: $cashFlow->created_at;
            $cancelledAt = $cashFlow->cancelled_at ?: $cashFlow->updated_at ?: $originalTime;
            $common = [
                'code' => $cashFlow->code,
                'domain' => 'customer',
                'document_amount' => (float) $cashFlow->amount,
                'amount' => (float) $cashFlow->amount,
                'reference_type' => $cashFlow->reference_type ?: 'CashFlow',
                'reference_id' => $cashFlow->id,
                'reference_code' => $cashFlow->reference_code,
                'source_table' => 'cash_flows',
                'detail_available' => true,
                'detail_modal_type' => 'cash_flow',
                'detail_reference_id' => $cashFlow->id,
                'detail_reference_code' => $cashFlow->code,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'document_first',
            ];
            $entries->push($this->createEntry(array_merge($common, [
                'id' => 'cancelled-cash-flow-original-'.$cashFlow->id,
                'display_type' => $kind === 'customer_payment' ? 'Khách thanh toán' : 'Hoàn tiền khách',
                'event_kind' => $kind,
                'display_effect' => $originalDelta,
                'customer_display_effect' => $originalDelta,
                'time' => $originalTime,
                'display_time' => $originalTime,
                'created_at' => $cashFlow->created_at,
                'source_id' => $cashFlow->id,
                'source_status' => 'cancelled',
            ])));
            $entries->push($this->createEntry(array_merge($common, [
                'id' => 'cancelled-cash-flow-reversal-'.$cashFlow->id,
                'display_type' => 'Hủy phiếu '.($cashFlow->type === 'receipt' ? 'thu' : 'chi'),
                'event_kind' => $kind.'_cancel_reversal',
                'display_effect' => -$originalDelta,
                'customer_display_effect' => -$originalDelta,
                'time' => $cancelledAt,
                'display_time' => $cancelledAt,
                'created_at' => $cancelledAt,
                'source_id' => $cashFlow->id.':cancel',
                'source_status' => 'cancelled',
                'reversal_of_event_identity' => $originalIdentity,
            ])));
        }

        // 6. Sales Returns (OrderReturns)
        $returns = OrderReturn::where('customer_id', $customer->id)
            ->get()
            ->values();

        foreach ($returns as $return) {
            $businessTime = ($return->return_date ?? null) ?: $return->created_at;
            $realRefund = (float) $return->paid_to_customer > 0
                ? $this->findRealRefundCashFlowForReturn($return, $payments)
                : null;
            $refundOriginalIdentity = $realRefund
                ? 'customer|cash_flows|'.$realRefund['cash_flow']->id.'|refund|receivable'
                : null;

            $entries->push($this->createEntry([
                'id' => 'return-'.$return->id,
                'code' => $return->code,
                'display_type' => 'Trả hàng bán',
                'event_kind' => 'sales_return',
                'domain' => 'customer',
                'source_status' => $return->status,
                'document_amount' => (float) $return->total,
                'amount' => (float) $return->total,
                'display_effect' => -(float) $return->total,
                'customer_display_effect' => -(float) $return->total,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $return->created_at,
                'reference_type' => 'OrderReturn',
                'reference_id' => $return->id,
                'reference_code' => $return->code,
                'detail_available' => true,
                'detail_modal_type' => 'return',
                'detail_reference_id' => $return->id,
                'detail_reference_code' => $return->code,
                'badge_label' => 'Trả hàng',
                'badge_title' => 'Trả hàng bán',
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'fallback_suppressed_by_real_refund' => (bool) $realRefund,
                'real_refund_code' => $realRefund['cash_flow']?->code ?? null,
                'real_refund_id' => $realRefund['cash_flow']?->id ?? null,
                'refund_match_strategy' => $realRefund['strategy'] ?? null,
                'source' => 'document_first',
            ]));

            // Synthesise virtual refund only when no real refund cashflow is
            // present. If a PC... voucher already exists, the cashflow row
            // above is the source of truth; adding PCTH... would double count.
            if ((float) $return->paid_to_customer > 0 && ! $realRefund) {
                $entries->push($this->createEntry([
                    'id' => 'refund-fallback-'.$return->id,
                    'code' => 'PCTH'.preg_replace('/^TH/', '', $return->code),
                    'display_type' => 'Hoàn tiền khách',
                    'event_kind' => 'refund',
                    'domain' => 'customer',
                    'document_amount' => (float) $return->paid_to_customer,
                    'amount' => (float) $return->paid_to_customer,
                    'display_effect' => +(float) $return->paid_to_customer,
                    'customer_display_effect' => +(float) $return->paid_to_customer,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $return->created_at,
                    'reference_type' => 'OrderReturn',
                    'reference_id' => $return->id,
                    'reference_code' => $return->code,
                    'is_virtual_fallback' => true,
                    'is_real_voucher' => false,
                    'detail_available' => false,
                    'detail_modal_type' => 'none',
                    'badge_label' => 'Tạm tính',
                    'badge_title' => 'Tạm tính hoàn tiền khách từ phiếu trả hàng — chưa tìm thấy phiếu chi thật.',
                    'source' => 'document_first',
                ]));
                $refundOriginalIdentity = 'customer|order_returns|'.$return->id.'|refund|receivable';
            }

            if (BusinessStatus::isCancelled($return->status)) {
                $cancelledAt = $return->updated_at ?: $businessTime;
                $entries->push($this->createEntry([
                    'id' => 'return-cancel-'.$return->id,
                    'code' => 'HUY-'.$return->code,
                    'display_type' => 'Hủy trả hàng bán',
                    'event_kind' => 'sales_return_cancel_reversal',
                    'domain' => 'customer',
                    'document_amount' => (float) $return->total,
                    'amount' => (float) $return->total,
                    'display_effect' => (float) $return->total,
                    'customer_display_effect' => (float) $return->total,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'reference_type' => 'OrderReturn',
                    'reference_id' => $return->id,
                    'reference_code' => $return->code,
                    'source_table' => 'order_returns',
                    'source_id' => (string) $return->id,
                    'reversal_of' => 'customer|order_returns|'.$return->id.'|sales_return|receivable',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));

                if ($refundOriginalIdentity && (float) $return->paid_to_customer > 0.01) {
                    $refundAmount = (float) ($realRefund['cash_flow']->amount ?? $return->paid_to_customer);
                    $entries->push($this->createEntry([
                        'id' => 'return-refund-cancel-'.$return->id,
                        'code' => 'HUY-HT-'.$return->code,
                        'display_type' => 'Hủy hoàn tiền trả hàng bán',
                        'event_kind' => 'refund_cancel_reversal',
                        'domain' => 'customer',
                        'document_amount' => $refundAmount,
                        'amount' => -$refundAmount,
                        'display_effect' => -$refundAmount,
                        'customer_display_effect' => -$refundAmount,
                        'time' => $cancelledAt,
                        'display_time' => $cancelledAt,
                        'created_at' => $cancelledAt,
                        'reference_type' => 'OrderReturn',
                        'reference_id' => $return->id,
                        'reference_code' => $return->code,
                        'source_table' => 'order_returns',
                        'source_id' => $return->id.':refund',
                        'reversal_of' => $refundOriginalIdentity,
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'source' => 'document_first',
                    ]));
                }
            }
        }

        $adjustmentDebts = CustomerDebt::where('customer_id', $customer->id)->get();
        $paymentLedgerCodes = $adjustmentDebts
            ->filter(fn (CustomerDebt $debt): bool => (string) $debt->type === 'payment')
            ->pluck('ref_code')
            ->map(fn ($code): string => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
        $invalidatedCashFlowMirrorCodes = $paymentLedgerCodes->isEmpty()
            ? collect()
            : CashFlow::withTrashed()
                ->where('target_id', $customer->id)
                ->where('type', 'receipt')
                ->whereIn('code', $paymentLedgerCodes->all())
                ->get()
                ->filter(fn (CashFlow $cashFlow): bool => $this->isCustomerCashFlow($cashFlow))
                ->filter(fn (CashFlow $cashFlow): bool => $cashFlow->trashed()
                    || BusinessStatus::isCancelled($cashFlow->status))
                ->pluck('code')
                ->map(fn ($code): string => trim((string) $code))
                ->filter()
                ->flip();

        foreach ($adjustmentDebts as $debt) {
            $refCode = $debt->ref_code;

            if ($this->customerLedgerIsDocumentEvidence($debt, $invoices, $returns, $receipts, $payments)) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $debt->amount,
                    'reason' => 'customer_document_ledger_mirror_excluded',
                    'source' => 'customer_debts',
                ];

                continue;
            }

            $invalidatedCashFlowMirror = in_array((string) $debt->type, ['payment', 'payment_cancel'], true)
                && $invalidatedCashFlowMirrorCodes->has(trim((string) $refCode));
            $isTech = $this->isTechnicalLedgerCode($refCode) || $invalidatedCashFlowMirror;
            if ($isTech) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $debt->amount,
                    'reason' => $invalidatedCashFlowMirror
                        ? 'cancelled_cash_flow_ledger_mirror_reference_only'
                        : 'technical_ledger_excluded_from_document_timeline',
                    'source' => 'customer_debts',
                ];

                if (! $includeTechnical) {
                    continue;
                }
            }

            $businessTime = $debt->recorded_at ?: $debt->created_at;
            [$displayType, $eventKind, $badgeLabel] = $this->classifyCustomerDebt($debt);
            $typeRaw = $eventKind === 'invoice_cancel_reversal' ? 'invoice_cancel_reversal' : $debt->type;

            $entries->push($this->createEntry([
                'id' => 'customer_debt-'.$debt->id,
                'code' => $refCode ?: ('DC'.$debt->id),
                'display_type' => $displayType,
                'type_raw' => $typeRaw,
                'event_kind' => $eventKind,
                'domain' => 'customer',
                'document_amount' => abs((float) $debt->amount),
                'amount' => (float) $debt->amount,
                'display_effect' => (float) $debt->amount,
                'customer_display_effect' => $isTech ? 0.0 : (float) $debt->amount,
                'affects_document_balance' => ! $isTech,
                'excluded_from_document_balance' => $isTech,
                'excluded_reason' => $isTech
                    ? ($invalidatedCashFlowMirror
                        ? 'cancelled_cash_flow_ledger_mirror_reference_only'
                        : 'technical_ledger_merge_or_opening')
                    : null,
                'affects_canonical_balance' => ! $isTech,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $debt->created_at,
                'reference_type' => 'CustomerDebt',
                'reference_id' => $debt->id,
                'reference_code' => $refCode,
                'detail_available' => true,
                'detail_modal_type' => 'none',
                'badge_label' => $badgeLabel,
                'badge_title' => $debt->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'ledger',
            ]));
        }

        // 8. DebtOffsets for Customer (active non-dual-role)
        $offsets = ($isDualRole && ! $domainOnly)
            ? collect()
            : $this->debtOffsetsForPartner((int) $customer->id);
        foreach ($offsets as $offset) {
            $isReversal = (int) ($offset->reverses_debt_offset_id ?? 0) > 0;
            $offsetEffect = ($isReversal ? 1.0 : -1.0) * (float) $offset->amount;
            $entries->push($this->createEntry([
                'id' => 'offset-'.$offset->id,
                'code' => $offset->code,
                'display_type' => 'Điều chỉnh',
                'event_kind' => $isReversal ? 'debt_offset_reversal' : 'debt_offset',
                'domain' => 'customer',
                'source_status' => $offset->status,
                'document_amount' => (float) $offset->amount,
                'amount' => (float) $offset->amount,
                'display_effect' => $offsetEffect,
                'customer_display_effect' => $offsetEffect,
                'time' => $offset->created_at,
                'display_time' => $offset->created_at,
                'created_at' => $offset->created_at,
                'reference_type' => 'DebtOffset',
                'reference_id' => $offset->id,
                'reference_code' => $offset->code,
                'reversal_of' => $isReversal
                    ? 'partner|debt_offsets|'.$offset->reverses_debt_offset_id.'|debt_offset|both'
                    : null,
                'detail_available' => true,
                'detail_modal_type' => 'debt_offset',
                'detail_reference_id' => $offset->id,
                'detail_reference_code' => $offset->code,
                'badge_label' => 'Cấn trừ',
                'badge_title' => $offset->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'document_first',
            ]));

            if (! $isReversal && $offset->status === 'cancelled' && ! $offset->reversalVoucher()->exists()) {
                $cancelCode = 'HCB'.str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                $entries->push($this->createEntry([
                    'id' => 'offset-cancel-'.$offset->id,
                    'code' => $cancelCode,
                    'display_type' => 'Hủy cấn bằng',
                    'event_kind' => 'debt_offset_cancel',
                    'domain' => 'customer',
                    'document_amount' => (float) $offset->amount,
                    'amount' => (float) $offset->amount,
                    'display_effect' => +(float) $offset->amount,
                    'customer_display_effect' => +(float) $offset->amount,
                    'time' => $offset->cancelled_at ?: $offset->updated_at,
                    'display_time' => $offset->cancelled_at ?: $offset->updated_at,
                    'created_at' => $offset->cancelled_at ?: $offset->updated_at,
                    'reference_type' => 'DebtOffsetCancel',
                    'reference_id' => $offset->id,
                    'reference_code' => $offset->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'debt_offset',
                    'detail_reference_id' => $offset->id,
                    'detail_reference_code' => $offset->code,
                    'badge_label' => 'Hủy cấn bằng',
                    'badge_title' => $offset->cancel_reason ?: 'Hủy cấn bằng công nợ',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }
        }

        // 9. Dual-role Supplier Mirror
        if ($isDualRole && ! $domainOnly) {
            // Purchases
            $purchases = Purchase::where('supplier_id', $customer->id)
                ->where('status', '!=', 'cancelled')
                ->get();
            $purchaseCodes = $purchases->pluck('code')->filter()->toArray();

            foreach ($purchases as $p) {
                $businessTime = $p->purchase_date ?: $p->created_at;
                $entries->push($this->createEntry([
                    'id' => 'sup-purchase-'.$p->id,
                    'code' => $p->code,
                    'display_type' => 'Nhập hàng',
                    'event_kind' => 'purchase',
                    'domain' => 'supplier',
                    'document_amount' => (float) $p->total_amount,
                    'amount' => (float) $p->total_amount,
                    'display_effect' => -(float) $p->total_amount,
                    'customer_display_effect' => -(float) $p->total_amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $p->created_at,
                    'reference_type' => 'Purchase',
                    'reference_id' => $p->id,
                    'reference_code' => $p->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'purchase',
                    'detail_reference_id' => $p->id,
                    'detail_reference_code' => $p->code,
                    'badge_label' => 'Phiếu nhập',
                    'badge_title' => 'Phiếu nhập hàng từ nhà cung cấp',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // Supplier cash flow payments (phiếu chi)
            $supplierPayments = CashFlow::active()
                ->where('type', 'payment')
                ->where(function ($q) use ($customer, $purchaseCodes) {
                    $q->where(function ($q2) use ($customer) {
                        $q2->where('target_id', $customer->id)
                            ->whereIn('target_type', ['Nha cung cap', 'Nhà cung cấp', 'NhÃ  cung cáº¥p']);
                    })
                        ->orWhere(function ($q2) use ($customer) {
                            $q2->where('reference_type', 'SupplierPayment')
                                ->where('target_id', $customer->id);
                        })
                        ->orWhere(function ($q2) use ($purchaseCodes) {
                            $q2->where('reference_type', 'Purchase')
                                ->whereIn('reference_code', $purchaseCodes);
                        });
                })
                ->get();

            $paymentsByPurchase = [];
            foreach ($supplierPayments as $cf) {
                $refCode = $cf->reference_code;
                if ($cf->reference_type === 'Purchase' && $refCode && in_array($refCode, $purchaseCodes, true)) {
                    $paymentsByPurchase[$refCode][] = $cf;
                }

                $businessTime = $cf->time ?: $cf->created_at;
                $entries->push($this->createEntry([
                    'id' => 'sup-payment-'.$cf->id,
                    'code' => $cf->code,
                    'display_type' => 'Thanh toán NCC',
                    'event_kind' => 'supplier_payment',
                    'domain' => 'supplier',
                    'document_amount' => (float) $cf->amount,
                    'amount' => (float) $cf->amount,
                    'display_effect' => +(float) $cf->amount,
                    'customer_display_effect' => +(float) $cf->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => $cf->reference_type ?: 'Purchase',
                    'reference_id' => $cf->id,
                    'reference_code' => $cf->reference_code,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cf->id,
                    'detail_reference_code' => $cf->code,
                    'badge_label' => 'Thanh toán',
                    'badge_title' => 'Thanh toán nhà cung cấp',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // A cancelled PCPN is still visible on the dual-role customer
            // side as the same original + reversal mirror pair. Its
            // payment_cancel ledger row is evidence only and is excluded
            // below to avoid counting it twice.
            $cancelledSupplierPayments = CashFlow::withTrashed()
                ->where('target_id', $customer->id)
                ->where('type', 'payment')
                ->where('reference_type', 'SupplierPayment')
                ->get()
                ->filter(fn (CashFlow $cashFlow): bool => $cashFlow->trashed()
                    || BusinessStatus::isCancelled($cashFlow->status));
            foreach ($cancelledSupplierPayments as $cashFlow) {
                $businessTime = $cashFlow->time ?: $cashFlow->created_at;
                $cancelledAt = $cashFlow->cancelled_at ?: $cashFlow->updated_at ?: $businessTime;
                $originalIdentity = "supplier|cash_flows|{$cashFlow->id}|supplier_payment|payable";
                $common = [
                    'code' => $cashFlow->code,
                    'domain' => 'supplier',
                    'document_amount' => (float) $cashFlow->amount,
                    'amount' => (float) $cashFlow->amount,
                    'reference_type' => 'SupplierPayment',
                    'reference_id' => $cashFlow->id,
                    'reference_code' => $cashFlow->reference_code,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cashFlow->id,
                    'detail_reference_code' => $cashFlow->code,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ];
                $entries->push($this->createEntry(array_merge($common, [
                    'id' => 'cancelled-supplier-payment-original-'.$cashFlow->id,
                    'display_type' => 'Thanh toán NCC',
                    'event_kind' => 'supplier_payment',
                    'display_effect' => +(float) $cashFlow->amount,
                    'customer_display_effect' => +(float) $cashFlow->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cashFlow->created_at,
                    'source_status' => 'cancelled',
                ])));
                $entries->push($this->createEntry(array_merge($common, [
                    'id' => 'cancelled-supplier-payment-reversal-'.$cashFlow->id,
                    'display_type' => 'Hủy thanh toán NCC',
                    'event_kind' => 'supplier_payment_cancel_reversal',
                    'display_effect' => -(float) $cashFlow->amount,
                    'customer_display_effect' => -(float) $cashFlow->amount,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'source_status' => 'cancelled',
                    'reversal_of_event_identity' => $originalIdentity,
                ])));
            }

            // Fallback Purchase payments (TTNH)
            foreach ($purchases as $p) {
                if ((float) $p->paid_amount > 0) {
                    // Check if this purchase's payment is already represented in real payments
                    $hasRealPayment = false;
                    foreach ($supplierPayments as $cf) {
                        if ($cf->reference_type === 'Purchase' && $cf->reference_code === $p->code) {
                            $hasRealPayment = true;
                            break;
                        }
                        if ($cf->code === 'PCPN'.preg_replace('/^PN/', '', $p->code) || $cf->code === 'TTNH'.preg_replace('/^PN/', '', $p->code)) {
                            $hasRealPayment = true;
                            break;
                        }
                    }
                    if (! $hasRealPayment) {
                        $businessTime = $p->purchase_date ?: $p->created_at;
                        $entries->push($this->createEntry([
                            'id' => 'sup-purpay-fallback-'.$p->id,
                            'code' => 'TTNH'.preg_replace('/^PN/', '', $p->code),
                            'display_type' => 'Thanh toán NCC',
                            'event_kind' => 'supplier_payment_fallback',
                            'domain' => 'supplier',
                            'document_amount' => (float) $p->paid_amount,
                            'amount' => (float) $p->paid_amount,
                            'display_effect' => +(float) $p->paid_amount,
                            'customer_display_effect' => +(float) $p->paid_amount,
                            'time' => $businessTime,
                            'display_time' => $businessTime,
                            'created_at' => $p->created_at,
                            'reference_type' => 'Purchase',
                            'reference_id' => $p->id,
                            'reference_code' => $p->code,
                            'is_virtual_fallback' => true,
                            'is_real_voucher' => false,
                            'detail_available' => false,
                            'detail_modal_type' => 'none',
                            'badge_label' => 'Tạm tính',
                            'badge_title' => 'Tạm tính từ phiếu nhập — chưa có phiếu chi thật.',
                            'source' => 'document_first',
                        ]));
                    }
                }
            }

            // Purchase Returns (Trả hàng nhập)
            $purchaseReturns = PurchaseReturn::where('supplier_id', $customer->id)
                ->where('status', 'completed')
                ->get();

            foreach ($purchaseReturns as $pr) {
                $businessTime = $pr->return_date ?: $pr->created_at;
                $entries->push($this->createEntry([
                    'id' => 'sup-return-'.$pr->id,
                    'code' => $pr->code,
                    'display_type' => 'Trả hàng nhập',
                    'event_kind' => 'purchase_return',
                    'domain' => 'supplier',
                    'document_amount' => (float) $pr->total_amount,
                    'amount' => (float) $pr->total_amount,
                    'display_effect' => +(float) $pr->total_amount,
                    'customer_display_effect' => +(float) $pr->total_amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $pr->created_at,
                    'reference_type' => 'PurchaseReturn',
                    'reference_id' => $pr->id,
                    'reference_code' => $pr->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'purchase_return',
                    'detail_reference_id' => $pr->id,
                    'detail_reference_code' => $pr->code,
                    'badge_label' => 'Trả hàng',
                    'badge_title' => 'Trả hàng nhập cho nhà cung cấp',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // Other supplier transactions (adjustments, offsets, etc.)
            $otherSupplierTxs = SupplierDebtTransaction::where('supplier_id', $customer->id)
                ->whereNotIn('type', ['purchase', 'return', 'payment'])
                ->get()
                ->reject(fn (SupplierDebtTransaction $transaction): bool => (string) $transaction->type === 'payment_cancel'
                    && str_starts_with((string) $transaction->code, 'HPCPN'))
                ->values();

            foreach ($otherSupplierTxs as $stx) {
                $businessTime = Schema::hasColumn('supplier_debt_transactions', 'recorded_at')
                    ? ($stx->recorded_at ?? $stx->created_at)
                    : $stx->created_at;

                $typeLabels = [
                    'adjustment' => 'Điều chỉnh',
                    'discount' => 'Chiết khấu TT',
                    'offset' => 'Điều chỉnh',
                ];

                $entries->push($this->createEntry([
                    'id' => 'sup-stx-'.$stx->id,
                    'code' => $stx->code,
                    'display_type' => $typeLabels[$stx->type] ?? $stx->type,
                    'event_kind' => 'supplier_mirror_'.$stx->type,
                    'domain' => 'supplier',
                    'document_amount' => abs((float) $stx->amount),
                    'amount' => (float) $stx->amount,
                    'display_effect' => -1 * (float) $stx->amount,
                    'customer_display_effect' => -1 * (float) $stx->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $stx->created_at,
                    'reference_type' => 'SupplierDebtTransaction',
                    'reference_id' => $stx->id,
                    'reference_code' => $stx->code,
                    'detail_available' => false,
                    'detail_modal_type' => 'none',
                    'badge_label' => $typeLabels[$stx->type] ?? $stx->type,
                    'badge_title' => $stx->note,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // Supplier DebtOffsets
            $supplierOffsets = $this->debtOffsetsForPartner((int) $customer->id);
            $workflowOffsetsForConsolidation = Schema::hasColumn('debt_offsets', 'workflow_status')
                ? $supplierOffsets->whereNotNull('workflow_status')->values()
                : collect();
            foreach ($supplierOffsets as $offset) {
                $entries->push($this->createEntry([
                    'id' => 'sup-offset-'.$offset->id,
                    'code' => $offset->code,
                    'display_type' => 'Điều chỉnh',
                    'event_kind' => 'debt_offset',
                    'domain' => 'supplier',
                    'document_amount' => (float) $offset->amount,
                    'amount' => (float) $offset->amount,
                    'display_effect' => +(float) $offset->amount,
                    'customer_display_effect' => +(float) $offset->amount,
                    'time' => $offset->created_at,
                    'display_time' => $offset->created_at,
                    'created_at' => $offset->created_at,
                    'reference_type' => 'DebtOffset',
                    'reference_id' => $offset->id,
                    'reference_code' => $offset->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'debt_offset',
                    'detail_reference_id' => $offset->id,
                    'detail_reference_code' => $offset->code,
                    'badge_label' => 'Cấn trừ',
                    'badge_title' => $offset->note,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));

                if ($offset->status === 'cancelled' && ! $offset->reversalVoucher()->exists()) {
                    $cancelCode = 'HCB'.str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                    $entries->push($this->createEntry([
                        'id' => 'sup-offset-cancel-'.$offset->id,
                        'code' => $cancelCode,
                        'display_type' => 'Hủy cấn bằng',
                        'event_kind' => 'debt_offset_cancel',
                        'domain' => 'supplier',
                        'document_amount' => (float) $offset->amount,
                        'amount' => (float) $offset->amount,
                        'display_effect' => -(float) $offset->amount,
                        'customer_display_effect' => -(float) $offset->amount,
                        'time' => $offset->cancelled_at ?: $offset->updated_at,
                        'display_time' => $offset->cancelled_at ?: $offset->updated_at,
                        'created_at' => $offset->cancelled_at ?: $offset->updated_at,
                        'reference_type' => 'DebtOffsetCancel',
                        'reference_id' => $offset->id,
                        'reference_code' => $offset->code,
                        'detail_available' => true,
                        'detail_modal_type' => 'debt_offset',
                        'detail_reference_id' => $offset->id,
                        'detail_reference_code' => $offset->code,
                        'badge_label' => 'Hủy cấn bằng',
                        'badge_title' => $offset->cancel_reason ?: 'Hủy cấn bằng công nợ',
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'source' => 'document_first',
                    ]));
                }
            }
        }

        $entries = $this->consolidateWorkflowOffsetEvidence($entries, $workflowOffsetsForConsolidation);

        // Canonical identity is source based. Codes are human labels and may
        // legitimately collide across models, so they must never drive dedup.
        $deduped = [];
        foreach ($entries as $entry) {
            $identity = (string) ($entry['event_identity'] ?? $entry['id']);
            if (! isset($deduped[$identity])) {
                $deduped[$identity] = $entry;
            }
        }

        $entries = collect(array_values($deduped));

        // Add sorting group metadata to all entries
        $entries = $entries->map(function (array $entry) use ($invoices, $purchases) {
            $ownTime = $entry['display_time'] ?? $entry['time'] ?? $entry['created_at'] ?? null;
            $ownTimeCarbon = $ownTime instanceof Carbon ? $ownTime : ($ownTime ? Carbon::parse($ownTime) : Carbon::now());

            $entry['event_time'] = $ownTimeCarbon;
            $entry['event_sort_time'] = $this->normalizeSortableTime($ownTimeCarbon);

            $eventKind = $entry['event_kind'] ?? '';
            $type = $entry['reference_type'] ?? '';

            // Default orders
            $balanceOrder = 10;
            $displayOrder = 50;

            if (str_contains($eventKind, 'opening') || str_contains($eventKind, 'virtual_opening') || $eventKind === 'opening_balance') {
                $balanceOrder = 1;
                $displayOrder = 40;
            } elseif (in_array($eventKind, ['invoice', 'customer_sale'], true) || $type === 'Invoice') {
                $balanceOrder = 10;
                $displayOrder = 50;
            } elseif (in_array($eventKind, ['purchase'], true) || $type === 'Purchase') {
                $balanceOrder = 10;
                $displayOrder = 50;
            } elseif (in_array($eventKind, ['sales_return', 'purchase_return'], true) || $type === 'OrderReturn' || $type === 'PurchaseReturn') {
                $balanceOrder = 20;
                $displayOrder = 80;
            } elseif (in_array($eventKind, ['invoice_payment', 'invoice_payment_fallback', 'supplier_payment', 'supplier_payment_fallback', 'customer_payment', 'refund'], true)) {
                $balanceOrder = 30;
                $displayOrder = 90;
            } elseif (str_contains($eventKind, 'adjustment') || $type === 'CustomerDebt' || $type === 'SupplierDebtTransaction') {
                $balanceOrder = 40;
                $displayOrder = 40;
            }

            $entry['balance_order'] = $balanceOrder;
            $entry['display_order'] = $displayOrder;

            // Keep setting group metadata for backward compatibility (but not sorting)
            if (! isset($entry['sort_group_time']) || ! isset($entry['sort_group_key'])) {
                $sortGroupTime = $ownTimeCarbon;
                $sortGroupKey = $entry['code'] ?: $entry['id'];
                $sortGroupSequence = (int) ($entry['display_sequence'] ?? 50);

                $refCode = $entry['reference_code'] ?? null;

                if (in_array($eventKind, ['invoice_payment', 'invoice_payment_fallback'], true) && $refCode) {
                    $parentInvoice = $invoices->firstWhere('code', $refCode);
                    if ($parentInvoice) {
                        $parentTime = $parentInvoice->transaction_date ?: $parentInvoice->created_at;
                        $sortGroupTime = $parentTime instanceof Carbon ? $parentTime : Carbon::parse($parentTime);
                        $sortGroupKey = $parentInvoice->code;
                    }
                }

                if (in_array($eventKind, ['supplier_payment', 'supplier_payment_fallback'], true) && $refCode) {
                    $parentPurchase = $purchases->firstWhere('code', $refCode);
                    if ($parentPurchase) {
                        $parentTime = $parentPurchase->purchase_date ?: $parentPurchase->created_at;
                        $sortGroupTime = $parentTime instanceof Carbon ? $parentTime : Carbon::parse($parentTime);
                        $sortGroupKey = $parentPurchase->code;
                    }
                }

                $groupTimeStr = $sortGroupTime->toIso8601String();
                $entry['sort_group_time'] = $groupTimeStr;
                $entry['sort_group_key'] = (string) $sortGroupKey;
                $entry['sort_group_sequence'] = $sortGroupSequence;

                // Group-first KiotViet metadata fields
                $entry['document_group_key'] = (string) $sortGroupKey;
                $entry['document_group_type'] = (in_array($eventKind, ['invoice_payment', 'invoice_payment_fallback', 'customer_sale'], true) || $entry['reference_type'] === 'Invoice') ? 'invoice' : ((in_array($eventKind, ['supplier_payment', 'supplier_payment_fallback', 'purchase'], true) || $entry['reference_type'] === 'Purchase') ? 'purchase' : 'other');
                $entry['document_group_parent_code'] = (string) $sortGroupKey;
                $entry['document_group_time'] = $groupTimeStr;
                $entry['document_group_sequence'] = $sortGroupSequence;
            } else {
                if ($entry['sort_group_time'] instanceof Carbon) {
                    $entry['sort_group_time'] = $entry['sort_group_time']->toIso8601String();
                }
                if ($entry['document_group_time'] instanceof Carbon) {
                    $entry['document_group_time'] = $entry['document_group_time']->toIso8601String();
                }
            }

            return $entry;
        });

        // Sort ASC for running balance calculation
        $sortedAsc = collect($entries)
            ->sort(function (array $a, array $b) {
                $timeCompare = strcmp(
                    (string) ($a['event_sort_time'] ?? ''),
                    (string) ($b['event_sort_time'] ?? '')
                );

                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                // Tie-breaker ASC để nếu cùng thời điểm, chứng từ phát sinh nợ đứng trước thanh toán.
                $balanceOrderCompare = ((int) ($a['balance_order'] ?? $a['event_order'] ?? 999))
                    <=> ((int) ($b['balance_order'] ?? $b['event_order'] ?? 999));

                if ($balanceOrderCompare !== 0) {
                    return $balanceOrderCompare;
                }

                return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
            })
            ->values();

        // Calculate chronological running balance
        $running = 0.0;
        $sorted = $sortedAsc->map(function (array $entry) use (&$running) {
            $effect = (float) ($entry['customer_display_effect'] ?? $entry['display_effect'] ?? $entry['amount'] ?? 0);

            if (($entry['affects_document_balance'] ?? true) === false) {
                $entry['customer_display_running_balance'] = $running;
                $entry['running_balance'] = $running;

                return $entry;
            }

            $running += $effect;

            $entry['customer_display_effect'] = $effect;
            $entry['display_effect'] = (float) ($entry['display_effect'] ?? $effect);
            $entry['customer_display_running_balance'] = $running;
            $entry['running_balance'] = $running;

            return $entry;
        });

        $documentFinalBalance = $running;

        // Sort DESC for display
        $displayEntries = $sorted
            ->sort(function (array $a, array $b) {
                $timeCompare = strcmp(
                    (string) ($b['event_sort_time'] ?? ''),
                    (string) ($a['event_sort_time'] ?? '')
                );

                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                // Display tie-breaker DESC để phiếu thanh toán cùng timestamp có thể nằm trên hóa đơn như Kiot.
                $displayOrderCompare = ((int) ($b['display_order'] ?? $b['event_order'] ?? 0))
                    <=> ((int) ($a['display_order'] ?? $a['event_order'] ?? 0));

                if ($displayOrderCompare !== 0) {
                    return $displayOrderCompare;
                }

                return strcmp((string) ($b['code'] ?? ''), (string) ($a['code'] ?? ''));
            })
            ->values();

        // Format all Carbon instances to standard string format before returning
        $displayEntries = $displayEntries->map(function ($entry) {
            $time = $entry['time'] ?? null;
            $displayTime = $entry['display_time'] ?? null;
            $createdAt = $entry['created_at'] ?? null;

            $entry['time'] = $time instanceof Carbon ? $time->toDateTimeString() : (string) $time;
            $entry['display_time'] = $displayTime instanceof Carbon ? $displayTime->toDateTimeString() : (string) $displayTime;
            $entry['created_at'] = $createdAt instanceof Carbon ? $createdAt->toDateTimeString() : (string) $createdAt;

            return $entry;
        });

        // Stored projection is evidence to compare, never an input used to
        // translate running balances or manufacture an opening event.
        $storedCustomerDebt = (float) ($customer->debt_amount ?? 0);
        $storedSupplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $storedNet = ($isDualRole && ! $domainOnly)
            ? $storedCustomerDebt - $storedSupplierDebt
            : $storedCustomerDebt;

        $rawDocumentFinalBalance = $documentFinalBalance;
        $displayFinalBalance = $rawDocumentFinalBalance;
        $difference = $rawDocumentFinalBalance - $storedNet;
        $rawMismatch = abs($difference) > 1.0;
        $isMismatch = $rawMismatch;

        $severity = 'ok';
        $message = null;
        if ($isMismatch) {
            $severity = 'warning';
            $message = 'Timeline chứng từ lệch với Nợ hiện tại. Cần đối soát dữ liệu, chưa tự sửa.';
        }

        return [
            'entries' => $displayEntries,
            'summary' => [
                'current_debt' => $storedNet,
                'stored_customer_debt' => $storedCustomerDebt,
                'stored_supplier_debt' => $storedSupplierDebt,
                'document_final_balance' => $rawDocumentFinalBalance,
                'raw_document_final_balance' => $rawDocumentFinalBalance,
                'document_final_balance_before_alignment' => $rawDocumentFinalBalance,
                'is_dual_role' => $isDualRole,
                'mode' => 'document_first',
                'count' => $displayEntries->count(),
                // Alignment keys
                'customer_debt_amount' => $storedCustomerDebt,
                'supplier_debt_amount' => $storedSupplierDebt,
                'net_debt_amount' => $storedNet,
                'net' => $storedNet,
                'display_balance_target' => $storedNet,
                'display_balance_final' => $displayFinalBalance,
                'display_alignment_amount' => 0.0,
                'display_aligned' => false,
                'has_virtual_display_alignment' => false,
                'has_virtual_opening_balance' => false,
                'virtual_opening_balance' => 0.0,
            ],
            'reconcile' => [
                'severity' => $severity,
                'message' => $message,
                'user_warning' => $isMismatch,
                'stored_balance' => $storedNet,
                'document_balance' => $rawDocumentFinalBalance,
                'raw_document_balance' => $rawDocumentFinalBalance,
                'difference' => $difference,
                // Alignment keys
                'computed_balance' => $rawDocumentFinalBalance,
                'has_mismatch' => $isMismatch,
                'raw_has_mismatch' => $rawMismatch,
                'ledger_mismatch' => false,
                'display_resolved' => ! $isMismatch,
                'display_balance_target' => $storedNet,
                'display_balance_final' => $displayFinalBalance,
                'display_alignment_amount' => 0.0,
                'display_aligned' => false,
                'has_virtual_display_alignment' => false,
                'has_virtual_opening_balance' => false,
                'excluded_ledger_entries' => $excludedLedgerEntries,
            ],
        ];
    }

    private function classifyCustomerDebt(CustomerDebt $debt): array
    {
        $type = (string) $debt->type;
        $refCode = (string) ($debt->ref_code ?? '');
        $note = mb_strtolower((string) ($debt->note ?? ''));
        $amount = (float) $debt->amount;

        if ($type === 'sale') {
            return ['Bán hàng', 'customer_sale', 'Ledger'];
        }
        if ($type === 'payment') {
            if (str_starts_with($refCode, 'CKTT')) {
                return [$amount > 0 ? 'Hủy chiết khấu thanh toán' : 'Chiết khấu thanh toán', $amount > 0 ? 'payment_discount_cancel' : 'payment_discount', 'Chiết khấu'];
            }

            return ['Khách thanh toán', 'customer_payment', 'Thanh toán'];
        }
        if ($type === 'return') {
            return ['Trả hàng bán', 'sales_return', 'Trả hàng'];
        }
        if ($type === 'sale_reversal' || $type === 'invoice_cancel_reversal') {
            return ['Hủy hóa đơn', 'invoice_cancel_reversal', 'Ledger'];
        }
        if ($type === 'adjustment') {
            if (str_contains($note, 'huy hoa don') || str_contains($note, 'hủy hóa đơn')) {
                return ['Hủy hóa đơn', 'invoice_cancel_reversal', 'Ledger'];
            }
            if (str_starts_with($refCode, 'MERGE') || str_starts_with($refCode, 'OPENING-BALANCE') || str_contains($note, 'gộp công nợ') || str_contains($note, 'gop cong no')) {
                return ['Số dư đầu kỳ / Gộp công nợ', 'opening_balance', 'Số dư đầu kỳ'];
            }
            if (str_starts_with($refCode, 'CKTT')) {
                return [$amount > 0 ? 'Hủy chiết khấu thanh toán' : 'Chiết khấu thanh toán', $amount > 0 ? 'payment_discount_cancel' : 'payment_discount', 'Chiết khấu'];
            }

            return ['Điều chỉnh công nợ', 'customer_adjustment', 'Điều chỉnh'];
        }
        if ($type === 'offset') {
            return ['Điều chỉnh', 'debt_offset', 'Cấn trừ'];
        }

        // Default fallback
        $eventKind = 'debt_adjustment';
        $displayType = 'Điều chỉnh công nợ';
        $badgeLabel = 'Điều chỉnh';

        if ($refCode) {
            if (str_starts_with($refCode, 'OPENING-BALANCE') || str_starts_with($refCode, 'MERGE')) {
                $eventKind = 'opening_balance';
                $displayType = 'Số dư đầu kỳ / Gộp công nợ';
                $badgeLabel = 'Số dư đầu kỳ';
            } elseif (str_starts_with($refCode, 'CKTT')) {
                $eventKind = 'payment_discount';
                $displayType = 'Chiết khấu thanh toán';
                $badgeLabel = 'Chiết khấu';
            } elseif (str_starts_with($refCode, 'CB') || str_starts_with($refCode, 'HCB')) {
                $eventKind = 'debt_offset';
                $displayType = 'Điều chỉnh';
                $badgeLabel = 'Cấn trừ';
            }
        }

        return [$displayType, $eventKind, $badgeLabel];
    }

    private function consolidateWorkflowOffsetEvidence(Collection $entries, Collection $workflowOffsets): Collection
    {
        foreach ($workflowOffsets as $offset) {
            $code = (string) $offset->code;
            $matching = $entries->filter(fn (array $entry): bool => (string) ($entry['code'] ?? '') === $code);
            $voucher = $matching->first(fn (array $entry): bool => ($entry['id'] ?? null) === 'sup-offset-'.$offset->id);
            if (! is_array($voucher)) {
                continue;
            }

            $economicEvidence = $matching->reject(
                fn (array $entry): bool => str_starts_with((string) ($entry['id'] ?? ''), 'sup-offset-')
            );
            $netEffect = (float) $economicEvidence->sum(
                fn (array $entry): float => (float) ($entry['customer_display_effect'] ?? $entry['display_effect'] ?? 0)
            );

            $voucher['display_effect'] = $netEffect;
            $voucher['customer_display_effect'] = $netEffect;
            $voucher['customer_effect'] = $netEffect;
            $voucher['evidence_consolidated'] = true;
            $voucher['evidence_sources'] = $economicEvidence
                ->map(fn (array $entry): array => [
                    'id' => $entry['id'] ?? null,
                    'reference_type' => $entry['reference_type'] ?? null,
                    'reference_id' => $entry['reference_id'] ?? null,
                ])
                ->values()
                ->all();

            $entries = $entries
                ->reject(fn (array $entry): bool => (string) ($entry['code'] ?? '') === $code)
                ->push($voucher);
        }

        return $entries->values();
    }

    private function createEntry(array $data): array
    {
        if (! array_key_exists('type', $data) && array_key_exists('display_type', $data)) {
            $data['type'] = $data['display_type'];
        }

        $entry = array_merge([
            'id' => null,
            'code' => null,
            'display_type' => null,
            'type_raw' => null,
            'event_kind' => null,
            'domain' => null,
            'document_group_key' => null,
            'document_group_type' => null,
            'document_group_parent_code' => null,
            'document_group_time' => null,
            'document_group_sequence' => null,
            'sort_group_key' => null,
            'sort_group_time' => null,
            'sort_group_sequence' => null,
            'document_amount' => 0.0,
            'amount' => 0.0,
            'display_effect' => 0.0,
            'customer_display_effect' => 0.0,
            'customer_effect' => $data['customer_display_effect'] ?? 0.0,
            'affects_debt_balance' => true,
            'time' => null,
            'display_time' => null,
            'created_at' => null,
            'reference_type' => null,
            'reference_id' => null,
            'reference_code' => null,
            'detail_available' => false,
            'detail_modal_type' => 'none',
            'detail_reference_id' => null,
            'detail_reference_code' => null,
            'badge_label' => null,
            'badge_title' => null,
            'is_real_voucher' => true,
            'is_virtual_fallback' => false,
            'affects_canonical_balance' => true,
            'display_sequence' => $this->getDisplaySequence($data),
        ], $data);

        $isCashFlow = ($entry['detail_modal_type'] ?? null) === 'cash_flow'
            || str_contains(str_replace('_', '-', (string) $entry['id']), 'cash-flow');
        $sourceTable = (string) ($entry['source_table'] ?? ($isCashFlow
            ? 'cash_flows'
            : $this->sourceTableForReference($entry['reference_type'])));
        $sourceId = (string) ($entry['source_id'] ?? ($isCashFlow
            ? ($entry['detail_reference_id'] ?? $entry['reference_id'] ?? $entry['id'])
            : ($entry['reference_id'] ?? $entry['id'])));
        $effectSide = (string) ($entry['effect_side'] ?? ($entry['domain'] === 'supplier' ? 'payable' : 'receivable'));
        $entry['source_table'] = $sourceTable;
        $entry['source_id'] = $sourceId;
        $entry['effect_side'] = $effectSide;
        $entry['event_identity'] = implode('|', [
            (string) $entry['domain'],
            $sourceTable,
            $sourceId,
            (string) $entry['event_kind'],
            $effectSide,
        ]);

        return $entry;
    }

    /** @return array<string, float> */
    private function legacyInvoiceAllocations(string $reference): array
    {
        $allocations = [];
        if ($reference === '') {
            return $allocations;
        }

        preg_match_all('/(HD[A-Z0-9_-]+)\s*:\s*([0-9][0-9.,]*)/iu', $reference, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $invoiceCode = (string) $match[1];
            $amount = $this->parseLegacyMoney((string) $match[2]);
            if ($amount > 0.01) {
                $allocations[$invoiceCode] = ($allocations[$invoiceCode] ?? 0.0) + $amount;
            }
        }

        return $allocations;
    }

    private function parseLegacyMoney(string $value): float
    {
        $normalized = preg_replace('/[^0-9.,]/', '', trim($value)) ?? '';
        if ($normalized === '') {
            return 0.0;
        }

        $lastDot = strrpos($normalized, '.');
        $lastComma = strrpos($normalized, ',');
        $separator = null;
        if ($lastDot !== false && $lastComma !== false) {
            $candidate = $lastDot > $lastComma ? '.' : ',';
            $fractionLength = strlen($normalized) - max($lastDot, $lastComma) - 1;
            $separator = $fractionLength > 0 && $fractionLength <= 2 ? $candidate : null;
        } elseif ($lastDot !== false || $lastComma !== false) {
            $candidate = $lastDot !== false ? '.' : ',';
            $position = $lastDot !== false ? $lastDot : $lastComma;
            $fractionLength = strlen($normalized) - $position - 1;
            if (substr_count($normalized, $candidate) === 1 && $fractionLength > 0 && $fractionLength <= 2) {
                $separator = $candidate;
            }
        }

        if ($separator === null) {
            return (float) str_replace([',', '.'], '', $normalized);
        }

        $thousandsSeparator = $separator === '.' ? ',' : '.';
        $normalized = str_replace($thousandsSeparator, '', $normalized);
        if ($separator === ',') {
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }

    private function sourceTableForReference(?string $referenceType): string
    {
        return match ((string) $referenceType) {
            'Invoice' => 'invoices',
            'OrderReturn' => 'order_returns',
            'Purchase' => 'purchases',
            'PurchaseReturn' => 'purchase_returns',
            'CustomerDebt' => 'customer_debts',
            'SupplierDebtTransaction' => 'supplier_debt_transactions',
            'DebtOffset', 'DebtOffsetCancel', 'DebtOffsetReversal' => 'debt_offsets',
            default => 'cash_flows',
        };
    }

    private function customerLedgerIsDocumentEvidence(
        CustomerDebt $debt,
        Collection $invoices,
        Collection $orderReturns,
        Collection $receipts,
        Collection $refunds,
    ): bool {
        $code = trim((string) ($debt->ref_code ?? ''));
        $type = (string) $debt->type;
        $returnById = (int) ($debt->order_return_id ?? 0) > 0
            ? $orderReturns->firstWhere('id', (int) $debt->order_return_id)
            : null;
        if ($returnById && in_array($type, ['return', 'adjustment'], true)) {
            return true;
        }
        if ($code === '') {
            return false;
        }

        $invoice = $invoices->firstWhere('code', $code);
        if ($type === 'sale' && $invoice) {
            return true;
        }
        if ($type === 'adjustment' && $invoice && BusinessStatus::isCancelled($invoice->status)) {
            return true;
        }
        if ($type === 'return' && $orderReturns->contains(
            fn (OrderReturn $return): bool => (string) $return->code === $code,
        )) {
            return true;
        }
        if ($type === 'adjustment') {
            return $receipts->concat($refunds)->contains(function (CashFlow $cashFlow) use ($code, $debt): bool {
                if ((string) $cashFlow->reference_type !== 'DebtAdjustment'
                    || ((string) $cashFlow->code !== $code && (string) $cashFlow->reference_code !== $code)) {
                    return false;
                }

                $documentDelta = $cashFlow->type === 'receipt'
                    ? -(float) $cashFlow->amount
                    : (float) $cashFlow->amount;

                return abs($documentDelta - (float) $debt->amount) <= 0.01;
            });
        }
        if ($type !== 'payment') {
            return false;
        }

        return $receipts->concat($refunds)->contains(
            fn (CashFlow $cashFlow): bool => (string) $cashFlow->code === $code
                || (string) $cashFlow->reference_code === $code,
        ) || $invoices->contains(
            fn (Invoice $row): bool => $code === 'TTHD'.preg_replace('/^HD/', '', (string) $row->code),
        );
    }

    private function findRealRefundCashFlowForReturn(OrderReturn $return, Collection $payments): ?array
    {
        $returnCode = (string) ($return->code ?? '');
        $returnId = (int) $return->id;
        $refundAmount = (float) ($return->paid_to_customer ?? 0);

        if ($refundAmount <= 0.01) {
            return null;
        }

        $candidates = $payments
            ->filter(function (CashFlow $cashFlow) use ($refundAmount) {
                if ((float) $cashFlow->amount <= 0.01) {
                    return false;
                }

                if (abs((float) $cashFlow->amount - $refundAmount) > 0.01) {
                    return false;
                }

                return $cashFlow->reference_type !== 'DebtAdjustment';
            })
            ->values();

        $referenceTypes = [
            'OrderReturn',
            OrderReturn::class,
            'Return',
            'SalesReturn',
            'returns',
        ];

        $exactById = $candidates->first(function (CashFlow $cashFlow) use ($referenceTypes, $returnId) {
            return in_array((string) $cashFlow->reference_type, $referenceTypes, true)
                && (int) ($cashFlow->reference_id ?? 0) === $returnId;
        });
        if ($exactById) {
            return ['cash_flow' => $exactById, 'strategy' => 'reference_type_and_id'];
        }

        if ($returnCode !== '') {
            $exactByCode = $candidates->first(function (CashFlow $cashFlow) use ($referenceTypes, $returnCode) {
                return in_array((string) $cashFlow->reference_type, $referenceTypes, true)
                    && (string) ($cashFlow->reference_code ?? '') === $returnCode;
            });
            if ($exactByCode) {
                return ['cash_flow' => $exactByCode, 'strategy' => 'reference_type_and_code'];
            }

            $referenceCodeOnly = $candidates->first(fn (CashFlow $cashFlow) => (string) ($cashFlow->reference_code ?? '') === $returnCode);
            if ($referenceCodeOnly) {
                return ['cash_flow' => $referenceCodeOnly, 'strategy' => 'reference_code'];
            }
        }

        $returnTime = $return->return_date ?: $return->created_at;
        if (! $returnTime) {
            return null;
        }

        $returnAt = Carbon::parse($returnTime);
        $fuzzy = $candidates
            ->filter(function (CashFlow $cashFlow) use ($returnAt) {
                $code = strtoupper((string) ($cashFlow->code ?? ''));
                if (! str_starts_with($code, 'PC')) {
                    return false;
                }

                $flowTime = $cashFlow->time ?: $cashFlow->created_at;
                if (! $flowTime) {
                    return false;
                }

                return abs(Carbon::parse($flowTime)->diffInMinutes($returnAt, false)) <= 60;
            })
            ->sortBy(function (CashFlow $cashFlow) use ($returnAt) {
                $flowTime = $cashFlow->time ?: $cashFlow->created_at;

                return abs(Carbon::parse($flowTime)->diffInSeconds($returnAt, false));
            })
            ->first();

        return $fuzzy ? ['cash_flow' => $fuzzy, 'strategy' => 'same_amount_same_customer_near_time'] : null;
    }

    private function customerCashFlowTargetTypes(): array
    {
        return PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES;
    }

    private function isCustomerCashFlow(CashFlow $cashFlow): bool
    {
        $targetType = mb_strtolower(trim((string) BusinessStatus::repairText($cashFlow->target_type)));

        return in_array($targetType, ['khách hàng', 'khach hang', 'kh??ch h??ng', 'customer'], true);
    }

    /**
     * Offset cash flows are persisted evidence for a DebtOffset document, not
     * a second receivable event. Keep an orphan cash flow visible when its
     * referenced document cannot be found.
     */
    private function isDebtOffsetEvidenceCashFlow(CashFlow $cashFlow): bool
    {
        if (! in_array((string) $cashFlow->reference_type, [
            'DebtOffset',
            DebtOffset::class,
            'DebtOffsetCancel',
            'DebtOffsetReversal',
        ], true)) {
            return false;
        }

        return DebtOffset::query()
            ->where('customer_id', (int) $cashFlow->target_id)
            ->where(function ($query) use ($cashFlow): void {
                $referenceId = (int) ($cashFlow->reference_id ?? 0);
                $referenceCode = trim((string) ($cashFlow->reference_code ?? ''));

                if ($referenceId > 0) {
                    $query->whereKey($referenceId);
                    if ($referenceCode !== '') {
                        $query->orWhere('code', $referenceCode);
                    }

                    return;
                }

                $query->where('code', $referenceCode);
            })
            ->exists();
    }

    private function debtOffsetsForPartner(int $partnerId): Collection
    {
        $query = DebtOffset::where('customer_id', $partnerId);
        if (Schema::hasColumn('debt_offsets', 'workflow_status')) {
            $query->where(function ($workflow): void {
                $workflow->whereNull('workflow_status')
                    ->orWhereIn('workflow_status', ['applied', 'reversed']);
            });
        }

        return $query->get();
    }

    private function getDisplaySequence(array $entry): int
    {
        $kind = $entry['event_kind'] ?? '';
        if (str_contains($kind, 'opening') || str_contains($kind, 'virtual_opening')) {
            return 5;
        }
        if ($kind === 'invoice' || $kind === 'purchase' || $kind === 'customer_sale') {
            return 10;
        }
        if ($kind === 'sales_return' || $kind === 'purchase_return') {
            return 15;
        }
        if (in_array($kind, ['invoice_payment', 'invoice_payment_fallback', 'supplier_payment', 'supplier_payment_fallback', 'customer_payment'], true)) {
            return 20;
        }

        return 50;
    }

    private function isTechnicalLedgerCode(?string $code): bool
    {
        if (! $code) {
            return false;
        }

        // Merge markers only link already-transferred documents and therefore
        // remain reference evidence. A persisted OPENING row is itself the
        // auditable signed evidence and must stay in the canonical reducer.
        return str_starts_with($code, 'MERGE');
    }

    private function normalizeSortableTime($value): string
    {
        if (! $value) {
            return '';
        }

        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
