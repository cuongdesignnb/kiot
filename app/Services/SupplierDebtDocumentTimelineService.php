<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SupplierDebtDocumentTimelineService
{
    public function build(Customer $supplier, array $options = []): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) ($supplier->is_supplier && ($hasSupplierColumn ? $supplier->is_customer : false));
        $usePartnerTimeline = $isDualRole && (string) ($options['view'] ?? '') === 'partner';

        $entries = collect();
        $purchases = collect();
        $invoices = collect();
        $excludedLedgerEntries = [];
        $includeTechnical = (bool) ($options['include_technical'] ?? $options['audit'] ?? false);

        // 1. Purchases
        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        $purchaseCodes = $purchases->pluck('code')->filter()->toArray();

        foreach ($purchases as $p) {
            $businessTime = $p->purchase_date ?: $p->created_at;
            $entries->push($this->createEntry([
                'id' => 'purchase-' . $p->id,
                'code' => $p->code,
                'display_type' => 'Nhập hàng',
                'event_kind' => 'purchase',
                'domain' => 'supplier',
                'document_amount' => (float) $p->total_amount,
                'amount' => (float) $p->total_amount,
                'display_effect' => (float) $p->total_amount,
                'supplier_display_effect' => (float) $p->total_amount,
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
                'document_group_key' => $p->code,
                'document_group_type' => 'purchase',
                'document_group_parent_code' => $p->code,
                'document_group_time' => $businessTime,
                'document_group_sequence' => 10,
                'sort_group_key' => $p->code,
                'sort_group_time' => $businessTime,
                'sort_group_sequence' => 10,
            ]));
        }

        // 2. Payment CashFlows targeting Nhà cung cấp (both linked and standalone)
        $supplierPayments = CashFlow::active()
            ->where('type', 'payment')
            ->where(function ($q) use ($supplier, $purchaseCodes) {
                $q->where(function ($q2) use ($supplier) {
                    $q2->where('target_id', $supplier->id)
                       ->whereIn('target_type', ['Nha cung cap', 'Nhà cung cấp']);
                })
                ->orWhere(function ($q2) use ($supplier) {
                    $q2->where('reference_type', 'SupplierPayment')
                       ->where('target_id', $supplier->id);
                })
                ->orWhere(function ($q2) use ($purchaseCodes) {
                    $q2->where('reference_type', 'Purchase')
                       ->whereIn('reference_code', $purchaseCodes);
                });
            })
            ->get();

        $paymentsByPurchase = [];
        $standalonePayments = [];

        foreach ($supplierPayments as $cf) {
            $refCode = $cf->reference_code;
            if ($cf->reference_type === 'Purchase' && $refCode && in_array($refCode, $purchaseCodes, true)) {
                $paymentsByPurchase[$refCode][] = $cf;
            } else {
                $standalonePayments[] = $cf;
            }
        }

        $realPaymentCoverageByPurchase = [];
        foreach ($paymentsByPurchase as $refCode => $cfs) {
            $realPaymentCoverageByPurchase[$refCode] = (float) collect($cfs)->sum('amount');
        }

        $genericPaymentInference = $this->inferGenericSupplierPaymentCoverage(
            $purchases,
            collect($standalonePayments),
            $realPaymentCoverageByPurchase
        );
        $genericPaymentCoverageByPurchase = $genericPaymentInference['coverage'];
        $genericPaymentAllocationDiagnostics = $genericPaymentInference['diagnostics'];

        foreach ($genericPaymentCoverageByPurchase as $purchaseCode => $coveredAmount) {
            $realPaymentCoverageByPurchase[$purchaseCode] = (float) ($realPaymentCoverageByPurchase[$purchaseCode] ?? 0.0)
                + (float) $coveredAmount;
        }

        // Emit linked payments
        foreach ($paymentsByPurchase as $refCode => $cfs) {
            $purchase = $purchases->firstWhere('code', $refCode);
            $purchasePaid = $purchase ? (float) $purchase->paid_amount : 0.0;
            $paymentTotal = (float) collect($cfs)->sum('amount');
            $mismatch = abs($paymentTotal - $purchasePaid) > 0.01;

            foreach ($cfs as $index => $cf) {
                $businessTime = $cf->time ?: $cf->created_at;
                $purchaseTime = $purchase ? ($purchase->purchase_date ?: $purchase->created_at) : ($cf->time ?: $cf->created_at);
                $entries->push($this->createEntry([
                    'id' => 'cash_flow-' . $cf->id,
                    'code' => $cf->code,
                    'display_type' => 'Thanh toán NCC',
                    'event_kind' => 'supplier_payment',
                    'domain' => 'supplier',
                    'document_amount' => (float) $cf->amount,
                    'amount' => (float) $cf->amount,
                    'display_effect' => -(float) $cf->amount,
                    'supplier_display_effect' => -(float) $cf->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => 'Purchase',
                    'reference_id' => $purchase ? $purchase->id : null,
                    'reference_code' => $refCode,
                    'parent_document_code' => $refCode,
                    'payment_for_code' => $refCode,
                    'linked_document_code' => $refCode,
                    'linked_document_label' => 'Thanh toán cho ' . $refCode,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cf->id,
                    'detail_reference_code' => $cf->code,
                    'badge_label' => $mismatch ? 'Cần đối soát' : 'Thanh toán',
                    'badge_title' => $mismatch ? 'Tổng phiếu chi thật không khớp số đã thanh toán trên hóa đơn nhập.' : null,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'receipt_allocation_mismatch' => $mismatch,
                    'needs_manual_review' => $mismatch,
                    'source' => 'document_first',
                    'document_group_key' => $refCode,
                    'document_group_type' => 'purchase',
                    'document_group_parent_code' => $refCode,
                    'document_group_time' => $purchaseTime,
                    'document_group_sequence' => 20 + $index,
                    'sort_group_key' => $refCode,
                    'sort_group_time' => $purchaseTime,
                    'sort_group_sequence' => 20 + $index,
                ]));
            }
        }

        // 3. Fallback Payment from purchase.paid_amount
        foreach ($purchases as $p) {
            $paidAmount = (float) $p->paid_amount;
            if ($paidAmount > 0) {
                $coveredAmount = max(0.0, (float) ($realPaymentCoverageByPurchase[$p->code] ?? 0.0));
                $genericInferredCoveredAmount = max(0.0, (float) ($genericPaymentCoverageByPurchase[$p->code] ?? 0.0));
                $uncoveredPaidAmount = max(0.0, $paidAmount - $coveredAmount);

                if ($uncoveredPaidAmount > 0.01) {
                    $businessTime = $p->purchase_date ?: $p->created_at;
                    $entries->push($this->createEntry([
                        'id' => 'purpay-fallback-' . $p->id,
                        'code' => 'TTNH' . preg_replace('/^PN/', '', $p->code),
                        'display_type' => 'Thanh toán NCC',
                        'event_kind' => 'supplier_payment_fallback',
                        'domain' => 'supplier',
                        'document_amount' => $uncoveredPaidAmount,
                        'amount' => $uncoveredPaidAmount,
                        'display_effect' => -$uncoveredPaidAmount,
                        'supplier_display_effect' => -$uncoveredPaidAmount,
                        'time' => $businessTime,
                        'display_time' => $businessTime,
                        'created_at' => $p->created_at,
                        'reference_type' => 'Purchase',
                        'reference_id' => $p->id,
                        'reference_code' => $p->code,
                        'parent_document_code' => $p->code,
                        'payment_for_code' => $p->code,
                        'linked_document_code' => $p->code,
                        'linked_document_label' => 'Thanh toán cho ' . $p->code,
                        'is_virtual_fallback' => true,
                        'is_virtual_payment' => true,
                        'is_real_voucher' => false,
                        'detail_available' => false,
                        'detail_modal_type' => 'none',
                        'badge_label' => 'Tạm tính',
                        'badge_title' => 'Tạm tính từ phiếu nhập — chưa tìm thấy phiếu chi thật.',
                        'source' => 'legacy_purchase_paid_amount',
                        'real_payment_covered_amount' => $coveredAmount,
                        'generic_payment_inferred_covered_amount' => $genericInferredCoveredAmount,
                        'payment_allocation_confidence' => $genericInferredCoveredAmount > 0.01 ? 'inferred' : 'actual_or_direct',
                        'fallback_uncovered_paid_amount' => $uncoveredPaidAmount,
                        'document_group_key' => $p->code,
                        'document_group_type' => 'purchase',
                        'document_group_parent_code' => $p->code,
                        'document_group_time' => $businessTime,
                        'document_group_sequence' => 20,
                        'sort_group_key' => $p->code,
                        'sort_group_time' => $businessTime,
                        'sort_group_sequence' => 20,
                    ]));
                }
            }
        }

        // 4. Standalone Payments
        foreach ($standalonePayments as $cf) {
            $businessTime = $cf->time ?: $cf->created_at;
            $entries->push($this->createEntry([
                'id' => 'cash_flow-' . $cf->id,
                'code' => $cf->code,
                'display_type' => 'Thanh toán NCC',
                'event_kind' => 'supplier_payment',
                'domain' => 'supplier',
                'document_amount' => (float) $cf->amount,
                'amount' => (float) $cf->amount,
                'display_effect' => -(float) $cf->amount,
                'supplier_display_effect' => -(float) $cf->amount,
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
                'badge_label' => 'Thanh toán',
                'badge_title' => $cf->description ?: $cf->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'payment_allocation_confidence' => 'global_payment_only',
                'allocation_is_actual' => false,
                'needs_manual_review' => (bool) ($genericPaymentAllocationDiagnostics['has_inferred_allocations'] ?? false),
                'payment_allocation_note' => 'Generic SupplierPayment has no persisted purchase allocation table; per-purchase coverage is inferred for display/reconcile diagnostics only.',
                'source' => 'document_first',
            ]));
        }

        // 5. Purchase Returns
        $purchaseReturns = PurchaseReturn::where('supplier_id', $supplier->id)
            ->where('status', 'completed')
            ->get();

        foreach ($purchaseReturns as $pr) {
            $businessTime = $pr->return_date ?: $pr->created_at;
            $entries->push($this->createEntry([
                'id' => 'purchase_return-' . $pr->id,
                'code' => $pr->code,
                'display_type' => 'Trả hàng nhập',
                'event_kind' => 'purchase_return',
                'domain' => 'supplier',
                'document_amount' => (float) $pr->total_amount,
                'amount' => (float) $pr->total_amount,
                'display_effect' => -(float) $pr->total_amount,
                'supplier_display_effect' => -(float) $pr->total_amount,
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

        // 6. Supplier Debt Transactions (adjustments, offsets, discounts)
        $supplierDebts = SupplierDebtTransaction::where('supplier_id', $supplier->id)->get();
        $existingCodes = $entries->pluck('code')->filter()->toArray();

        foreach ($supplierDebts as $stx) {
            $refCode = $stx->code;
            if ($refCode && in_array($refCode, $existingCodes, true)) {
                continue;
            }

            if ($this->isTechnicalLedgerCode($refCode) && !$includeTechnical) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $stx->amount,
                    'reason' => 'technical_ledger_excluded_from_document_timeline',
                    'source' => 'supplier_debt_transactions',
                ];
                continue;
            }

            $isTech = false;
            $businessTime = Schema::hasColumn('supplier_debt_transactions', 'recorded_at')
                ? ($stx->recorded_at ?? $stx->created_at)
                : $stx->created_at;

            [$displayType, $eventKind, $badgeLabel] = $this->classifySupplierDebt($stx);

            $entries->push($this->createEntry([
                'id' => 'supplier_debt-' . $stx->id,
                'code' => $refCode ?: ('DC' . $stx->id),
                'display_type' => $displayType,
                'event_kind' => $eventKind,
                'domain' => 'adjustment',
                'document_amount' => abs((float) $stx->amount),
                'amount' => (float) $stx->amount,
                'display_effect' => (float) $stx->amount,
                'supplier_display_effect' => $isTech ? 0.0 : (float) $stx->amount,
                'affects_document_balance' => !$isTech,
                'excluded_from_document_balance' => $isTech,
                'excluded_reason' => $isTech ? 'technical_ledger_merge_or_opening' : null,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $stx->created_at,
                'reference_type' => 'SupplierDebtTransaction',
                'reference_id' => $stx->id,
                'reference_code' => $refCode,
                'detail_available' => true,
                'detail_modal_type' => 'none',
                'badge_label' => $badgeLabel,
                'badge_title' => $stx->note,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'ledger',
            ]));
        }

        // 7. Supplier offsets (CB / HCB)
        $offsets = DebtOffset::where('customer_id', $supplier->id)
            ->whereNotIn('code', $existingCodes)
            ->get();

        foreach ($offsets as $offset) {
            $entries->push($this->createEntry([
                'id' => 'offset-' . $offset->id,
                'code' => $offset->code,
                'display_type' => 'Điều chỉnh',
                'event_kind' => 'debt_offset',
                'domain' => 'supplier',
                'document_amount' => (float) $offset->amount,
                'amount' => (float) $offset->amount,
                'display_effect' => -(float) $offset->amount,
                'supplier_display_effect' => -(float) $offset->amount,
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

            if ($offset->status === 'cancelled') {
                $cancelCode = 'HCB' . str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                $entries->push($this->createEntry([
                    'id' => 'offset-cancel-' . $offset->id,
                    'code' => $cancelCode,
                    'display_type' => 'Hủy điều chỉnh',
                    'event_kind' => 'debt_offset_cancel',
                    'domain' => 'supplier',
                    'document_amount' => (float) $offset->amount,
                    'amount' => (float) $offset->amount,
                    'display_effect' => +(float) $offset->amount,
                    'supplier_display_effect' => +(float) $offset->amount,
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
                    'badge_label' => 'Hủy điều chỉnh',
                    'badge_title' => $offset->cancel_reason ?: 'Hủy cấn bằng công nợ',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }
        }

        // 8. Dual-role Customer Mirror (if $usePartnerTimeline is true)
        if ($usePartnerTimeline) {
            // Customer Invoices
            $invoices = Invoice::where('customer_id', $supplier->id)
                ->where(function($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'Đã hủy');
                })
                ->get();
            $invoiceCodes = $invoices->pluck('code')->filter()->toArray();

            foreach ($invoices as $invoice) {
                $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cust-invoice-' . $invoice->id,
                    'code' => $invoice->code,
                    'display_type' => 'Bán hàng',
                    'event_kind' => 'customer_sale',
                    'domain' => 'customer',
                    'document_amount' => (float) $invoice->total,
                    'amount' => (float) $invoice->total,
                    'display_effect' => -(float) $invoice->total,
                    'supplier_display_effect' => -(float) $invoice->total,
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
                    'badge_label' => 'Bán hàng',
                    'badge_title' => 'Phiếu bán hàng cho khách hàng',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // Customer receipts (thu)
            $customerReceipts = CashFlow::active()
                ->where('type', 'receipt')
                ->where('target_id', $supplier->id)
                ->where('target_type', 'Khách hàng')
                ->get();

            $receiptsByInvoice = [];
            foreach ($customerReceipts as $cf) {
                $refCode = $cf->reference_code;
                if ($cf->reference_type === 'Invoice' && $refCode && in_array($refCode, $invoiceCodes, true)) {
                    $receiptsByInvoice[$refCode][] = $cf;
                }

                $businessTime = $cf->time ?: $cf->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cust-receipt-' . $cf->id,
                    'code' => $cf->code,
                    'display_type' => 'Khách thanh toán',
                    'event_kind' => 'invoice_payment',
                    'domain' => 'customer',
                    'document_amount' => (float) $cf->amount,
                    'amount' => (float) $cf->amount,
                    'display_effect' => +(float) $cf->amount,
                    'supplier_display_effect' => +(float) $cf->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => $cf->reference_type ?: 'Invoice',
                    'reference_id' => $cf->id,
                    'reference_code' => $cf->reference_code,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cf->id,
                    'detail_reference_code' => $cf->code,
                    'badge_label' => 'Thanh toán',
                    'badge_title' => 'Khách hàng thanh toán công nợ',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }

            // Fallback Customer receipts (TTHD)
            foreach ($invoices as $invoice) {
                if ((float) $invoice->customer_paid > 0) {
                    $hasRealReceipt = false;
                    foreach ($customerReceipts as $cf) {
                        if ($cf->reference_type === 'Invoice' && $cf->reference_code === $invoice->code) {
                            $hasRealReceipt = true;
                            break;
                        }
                        if ($cf->code === 'TTHD' . preg_replace('/^HD/', '', $invoice->code)) {
                            $hasRealReceipt = true;
                            break;
                        }
                    }
                    if (!$hasRealReceipt) {
                        $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                        $entries->push($this->createEntry([
                            'id' => 'cust-invpay-fallback-' . $invoice->id,
                            'code' => 'TTHD' . preg_replace('/^HD/', '', $invoice->code),
                            'display_type' => 'Khách thanh toán',
                            'event_kind' => 'invoice_payment_fallback',
                            'domain' => 'customer',
                            'document_amount' => (float) $invoice->customer_paid,
                            'amount' => (float) $invoice->customer_paid,
                            'display_effect' => +(float) $invoice->customer_paid,
                            'supplier_display_effect' => +(float) $invoice->customer_paid,
                            'time' => $businessTime,
                            'display_time' => $businessTime,
                            'created_at' => $invoice->created_at,
                            'reference_type' => 'Invoice',
                            'reference_id' => $invoice->id,
                            'reference_code' => $invoice->code,
                            'is_virtual_fallback' => true,
                            'is_real_voucher' => false,
                            'detail_available' => false,
                            'detail_modal_type' => 'none',
                            'badge_label' => 'Tạm tính',
                            'badge_title' => 'Tạm tính từ hóa đơn — chưa tìm thấy phiếu thu thật.',
                            'source' => 'document_first',
                        ]));
                    }
                }
            }

            // Customer Sales Returns (OrderReturns)
            $orderReturns = OrderReturn::where('customer_id', $supplier->id)
                ->where(function($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'Đã hủy');
                })
                ->get();

            foreach ($orderReturns as $or) {
                $businessTime = ($or->return_date ?? null) ?: $or->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cust-return-' . $or->id,
                    'code' => $or->code,
                    'display_type' => 'Trả hàng bán',
                    'event_kind' => 'sales_return',
                    'domain' => 'customer',
                    'document_amount' => (float) $or->total,
                    'amount' => (float) $or->total,
                    'display_effect' => +(float) $or->total,
                    'supplier_display_effect' => +(float) $or->total,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $or->created_at,
                    'reference_type' => 'OrderReturn',
                    'reference_id' => $or->id,
                    'reference_code' => $or->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'return',
                    'detail_reference_id' => $or->id,
                    'detail_reference_code' => $or->code,
                    'badge_label' => 'Trả hàng',
                    'badge_title' => 'Khách hàng trả hàng bán',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));

                if ((float) $or->paid_to_customer > 0) {
                    $entries->push($this->createEntry([
                        'id' => 'cust-refund-fallback-' . $or->id,
                        'code' => 'PCTH' . preg_replace('/^TH/', '', $or->code),
                        'display_type' => 'Hoàn tiền khách',
                        'event_kind' => 'refund',
                        'domain' => 'customer',
                        'document_amount' => (float) $or->paid_to_customer,
                        'amount' => (float) $or->paid_to_customer,
                        'display_effect' => -(float) $or->paid_to_customer,
                        'supplier_display_effect' => -(float) $or->paid_to_customer,
                        'time' => $businessTime,
                        'display_time' => $businessTime,
                        'created_at' => $or->created_at,
                        'reference_type' => 'OrderReturn',
                        'reference_id' => $or->id,
                        'reference_code' => $or->code,
                        'is_virtual_fallback' => true,
                        'is_real_voucher' => false,
                        'detail_available' => false,
                        'detail_modal_type' => 'none',
                        'badge_label' => 'Tạm tính',
                        'badge_title' => 'Tạm tính hoàn tiền khách từ phiếu trả hàng — chưa tìm thấy phiếu chi thật.',
                        'source' => 'document_first',
                    ]));
                }
            }

            // Customer Debt adjustments (CustomerDebt)
            $customerDebts = CustomerDebt::where('customer_id', $supplier->id)->get();
            foreach ($customerDebts as $debt) {
                $refCode = $debt->ref_code;
                if ($refCode && in_array($refCode, $existingCodes, true)) {
                    continue;
                }

                if ($this->isTechnicalLedgerCode($refCode) && !$includeTechnical) {
                    $excludedLedgerEntries[] = [
                        'code' => $refCode,
                        'amount' => (float) $debt->amount,
                        'reason' => 'technical_ledger_excluded_from_document_timeline',
                        'source' => 'customer_debts',
                    ];
                    continue;
                }

                $businessTime = $debt->recorded_at ?: $debt->created_at;
                [$displayType, $eventKind, $badgeLabel] = $this->classifyCustomerDebt($debt);

                $entries->push($this->createEntry([
                    'id' => 'customer_debt-' . $debt->id,
                    'code' => $refCode ?: ('DC' . $debt->id),
                    'display_type' => $displayType,
                    'event_kind' => $eventKind,
                    'domain' => 'customer',
                    'document_amount' => abs((float) $debt->amount),
                    'amount' => (float) $debt->amount,
                    'display_effect' => -(float) $debt->amount,
                    'supplier_display_effect' => -(float) $debt->amount,
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

            // Customer Offsets (CB / HCB)
            $customerOffsets = DebtOffset::where('customer_id', $supplier->id)->get();
            foreach ($customerOffsets as $offset) {
                $entries->push($this->createEntry([
                    'id' => 'cust-offset-' . $offset->id,
                    'code' => $offset->code,
                    'display_type' => 'Điều chỉnh',
                    'event_kind' => 'debt_offset',
                    'domain' => 'customer',
                    'document_amount' => (float) $offset->amount,
                    'amount' => (float) $offset->amount,
                    'display_effect' => +(float) $offset->amount,
                    'supplier_display_effect' => +(float) $offset->amount,
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

                if ($offset->status === 'cancelled') {
                    $cancelCode = 'HCB' . str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                    $entries->push($this->createEntry([
                        'id' => 'cust-offset-cancel-' . $offset->id,
                        'code' => $cancelCode,
                        'display_type' => 'Hủy điều chỉnh',
                        'event_kind' => 'debt_offset_cancel',
                        'domain' => 'customer',
                        'document_amount' => (float) $offset->amount,
                        'amount' => (float) $offset->amount,
                        'display_effect' => -(float) $offset->amount,
                        'supplier_display_effect' => -(float) $offset->amount,
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
                        'badge_label' => 'Hủy điều chỉnh',
                        'badge_title' => $offset->cancel_reason ?: 'Hủy cấn bằng công nợ',
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'source' => 'document_first',
                    ]));
                }
            }
        }

        // Dedup by non-null code
        $deduped = [];
        foreach ($entries as $entry) {
            $code = $entry['code'];
            if ($code) {
                if (!isset($deduped[$code])) {
                    $deduped[$code] = $entry;
                } else {
                    $existing = $deduped[$code];
                    if (($existing['source'] ?? '') === 'ledger' && ($entry['source'] ?? '') === 'document_first') {
                        $deduped[$code] = $entry;
                    } elseif (($existing['source'] ?? '') === 'document_first' && ($entry['source'] ?? '') === 'ledger') {
                        // Keep the existing document-first entry
                    } else {
                        $deduped[$code . '-' . $entry['id']] = $entry;
                    }
                }
            } else {
                $deduped[] = $entry;
            }
        }

        $entries = collect(array_values($deduped));

        // Add sorting group metadata to all entries
        $entries = $entries->map(function (array $entry) use ($purchases, $invoices) {
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
            if (!isset($entry['sort_group_time']) || !isset($entry['sort_group_key'])) {
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

        if ($usePartnerTimeline) {
            $entries = $entries
                ->map(fn (array $entry) => $this->normalizeSupplierPartnerDisplayAliases($entry))
                ->values();
        }

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

                $balanceOrderCompare = ((int) ($a['balance_order'] ?? 999))
                    <=> ((int) ($b['balance_order'] ?? 999));

                if ($balanceOrderCompare !== 0) {
                    return $balanceOrderCompare;
                }

                return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
            })
            ->values();

        // Calculate chronological running balance
        $running = 0.0;
        $sorted = $sortedAsc->map(function (array $entry) use (&$running) {
            $effect = (float) ($entry['supplier_display_effect'] ?? $entry['display_effect'] ?? $entry['amount'] ?? 0);
            $displayBalanceEffect = (float) ($entry['supplier_display_balance_effect'] ?? $effect);

            if (($entry['affects_document_balance'] ?? true) === false) {
                $entry['supplier_display_effect'] = $effect;
                $entry['supplier_display_balance_effect'] = $displayBalanceEffect;
                $entry['supplier_balance_effect'] = (float) ($entry['supplier_balance_effect'] ?? 0.0);
                $entry['supplier_display_running_balance'] = $running;
                $entry['running_balance'] = $running;
                return $entry;
            }

            $running += $displayBalanceEffect;

            $entry['supplier_display_effect'] = $effect;
            $entry['supplier_display_balance_effect'] = $displayBalanceEffect;
            $entry['supplier_balance_effect'] = (float) ($entry['supplier_balance_effect'] ?? $displayBalanceEffect);
            $entry['display_effect'] = (float) ($entry['display_effect'] ?? $effect);
            $entry['supplier_display_running_balance'] = $running;
            $entry['running_balance'] = $running;

            return $entry;
        });

        $sorted = $sorted->map(fn (array $entry) => $this->withCompatibilityAliases($entry));

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

                $displayOrderCompare = ((int) ($b['display_order'] ?? 0))
                    <=> ((int) ($a['display_order'] ?? 0));

                if ($displayOrderCompare !== 0) {
                    return $displayOrderCompare;
                }

                return strcmp((string) ($b['code'] ?? ''), (string) ($a['code'] ?? ''));
            })
            ->values();

        // Format times before returning
        $displayEntries = $displayEntries->map(function ($entry) {
            $time = $entry['time'] ?? null;
            $displayTime = $entry['display_time'] ?? null;
            $createdAt = $entry['created_at'] ?? null;

            $entry['time'] = $time instanceof Carbon ? $time->toDateTimeString() : (string) $time;
            $entry['display_time'] = $displayTime instanceof Carbon ? $displayTime->toDateTimeString() : (string) $displayTime;
            $entry['created_at'] = $createdAt instanceof Carbon ? $createdAt->toDateTimeString() : (string) $createdAt;

            return $entry;
        });

        // Stored balances & reconciliation
        $storedCustomerDebt = (float) ($supplier->debt_amount ?? 0);
        $storedSupplierDebt = $hasSupplierColumn ? (float) ($supplier->supplier_debt_amount ?? 0) : 0.0;

        if ($usePartnerTimeline) {
            $storedBalance = $storedSupplierDebt - $storedCustomerDebt;
            $balanceLabel = 'Nợ cần trả nhà cung cấp';
        } else {
            $storedBalance = $storedSupplierDebt;
            $balanceLabel = 'Nợ cần trả nhà cung cấp';
        }

        $virtualOpening = null;
        if ($usePartnerTimeline && $displayEntries->isNotEmpty() && abs($documentFinalBalance - $storedBalance) > 1.0) {
            $openingAmount = $storedBalance - $documentFinalBalance;
            $openingTime = $this->virtualOpeningTime($displayEntries);
            $virtualOpening = $this->withCompatibilityAliases($this->createEntry([
                'id' => 'virtual-opening-supplier-' . $supplier->id,
                'code' => 'OPENING-BALANCE-SUPPLIER-' . $supplier->id,
                'display_type' => 'So du dau ky',
                'event_kind' => 'virtual_opening_balance',
                'domain' => 'adjustment',
                'document_amount' => abs($openingAmount),
                'amount' => $openingAmount,
                'display_effect' => $openingAmount,
                'supplier_display_effect' => $openingAmount,
                'supplier_display_balance_effect' => $openingAmount,
                'supplier_balance_effect' => 0.0,
                'supplier_display_running_balance' => $openingAmount,
                'supplier_running_balance' => $openingAmount,
                'running_balance' => $openingAmount,
                'time' => $openingTime,
                'display_time' => $openingTime,
                'created_at' => $openingTime,
                'reference_type' => 'Customer',
                'reference_id' => $supplier->id,
                'reference_code' => $supplier->code,
                'badge_label' => 'So du dau ky',
                'badge_title' => 'Read-only display row for stored supplier partner balance.',
                'is_real_voucher' => false,
                'is_virtual_fallback' => true,
                'is_virtual_opening' => true,
                'source' => 'virtual_opening_balance',
                'source_ledger' => 'virtual_opening_balance',
                'affects_debt_balance' => false,
                'reference_only' => false,
                'is_reference_only' => false,
            ]));

            $displayEntries = $displayEntries
                ->map(fn (array $entry) => $this->shiftSupplierDisplayRunningAliases($entry, $openingAmount))
                ->prepend($virtualOpening)
                ->sort(function (array $a, array $b) {
                    $timeCompare = strcmp(
                        (string) ($b['event_sort_time'] ?? $b['time'] ?? ''),
                        (string) ($a['event_sort_time'] ?? $a['time'] ?? '')
                    );

                    if ($timeCompare !== 0) {
                        return $timeCompare;
                    }

                    $displayOrderCompare = ((int) ($b['display_order'] ?? 0))
                        <=> ((int) ($a['display_order'] ?? 0));

                    if ($displayOrderCompare !== 0) {
                        return $displayOrderCompare;
                    }

                    return strcmp((string) ($b['code'] ?? ''), (string) ($a['code'] ?? ''));
                })
                ->values();
            $documentFinalBalance = $storedBalance;
        }

        if ($displayEntries->isEmpty() && abs($storedBalance) > 1.0) {
            $virtualOpening = $this->withCompatibilityAliases($this->createEntry([
                'id' => 'virtual-opening-supplier-' . $supplier->id,
                'code' => 'OPENING-BALANCE-SUPPLIER-' . $supplier->id,
                'display_type' => 'Số dư đầu kỳ',
                'event_kind' => 'virtual_opening_balance',
                'domain' => 'supplier',
                'document_amount' => abs($storedBalance),
                'amount' => $storedBalance,
                'display_effect' => $storedBalance,
                'supplier_display_effect' => $storedBalance,
                'supplier_display_running_balance' => $storedBalance,
                'supplier_running_balance' => $storedBalance,
                'running_balance' => $storedBalance,
                'time' => $supplier->created_at,
                'display_time' => $supplier->created_at,
                'created_at' => $supplier->created_at,
                'reference_type' => 'Customer',
                'reference_id' => $supplier->id,
                'reference_code' => $supplier->code,
                'badge_label' => 'Số dư đầu kỳ',
                'badge_title' => 'Read-only display row for stored supplier debt when no documents exist.',
                'is_real_voucher' => false,
                'is_virtual_fallback' => true,
                'is_virtual_opening' => true,
                'source' => 'virtual_opening_balance',
                'source_ledger' => 'virtual_opening_balance',
            ]));

            $displayEntries = collect([$virtualOpening]);
            $documentFinalBalance = $storedBalance;
        }

        $difference = $documentFinalBalance - $storedBalance;
        $isMismatch = abs($difference) > 1.0;
        $hasInferredGenericAllocations = (bool) ($genericPaymentAllocationDiagnostics['has_inferred_allocations'] ?? false);
        $hasUnallocatedGenericPayments = (bool) ($genericPaymentAllocationDiagnostics['has_unallocated_generic_payments'] ?? false);

        $severity = 'ok';
        $message = null;
        if ($virtualOpening) {
            $severity = 'info';
        }
        if ($isMismatch) {
            $severity = 'warning';
            $message = 'Timeline chứng từ lệch với Nợ hiện tại. Cần đối soát dữ liệu, chưa tự sửa.';
        }
        if (!$isMismatch && !$virtualOpening && $hasInferredGenericAllocations) {
            $severity = 'warning';
            $message = 'Generic SupplierPayment has no persisted allocation table; purchase-level coverage is inferred for display only and needs review when manual allocation may have been used.';
        }
        if (!$isMismatch && !$virtualOpening && $hasUnallocatedGenericPayments) {
            $severity = 'warning';
            $message = 'Some Generic SupplierPayment rows cannot be safely inferred; fallback and diagnostics are kept read-only.';
        }
        if ($virtualOpening) {
            $severity = 'info';
            $message = null;
        }

        return [
            'entries' => $displayEntries,
            'summary' => [
                'current_debt' => $storedBalance,
                'stored_customer_debt' => $storedCustomerDebt,
                'stored_supplier_debt' => $storedSupplierDebt,
                'document_final_balance' => $documentFinalBalance,
                'is_dual_role' => $isDualRole,
                'mode' => 'document_first',
                'count' => $displayEntries->count(),
                // Alignment keys
                'customer_debt_amount' => $storedCustomerDebt,
                'supplier_debt_amount' => $storedSupplierDebt,
                'net_debt_amount' => $storedCustomerDebt - $storedSupplierDebt,
                'net' => $storedBalance,
                'display_balance_target' => $storedBalance,
                'display_balance_final' => $documentFinalBalance,
                'display_mode' => $usePartnerTimeline ? 'supplier_partner_timeline' : 'supplier_payable',
                'is_supplier_tab_partner_timeline' => $usePartnerTimeline,
                'balance_label' => $balanceLabel,
                'has_virtual_opening_balance' => (bool) $virtualOpening,
                'virtual_opening_balance' => (float) ($virtualOpening['supplier_display_effect'] ?? 0.0),
            ],
            'reconcile' => [
                'severity' => $severity,
                'message' => $message,
                'user_warning' => $virtualOpening ? false : ($isMismatch || $hasInferredGenericAllocations || $hasUnallocatedGenericPayments),
                'stored_balance' => $storedBalance,
                'document_balance' => $documentFinalBalance,
                'difference' => $difference,
                'allocation_confidence' => $hasInferredGenericAllocations ? 'inferred' : 'actual_or_legacy',
                'has_inferred_generic_allocations' => $hasInferredGenericAllocations,
                'has_unallocated_generic_payments' => $hasUnallocatedGenericPayments,
                'generic_payment_allocation' => $genericPaymentAllocationDiagnostics,
                // Alignment keys
                'computed_balance' => $storedBalance,
                'has_mismatch' => $virtualOpening ? false : $isMismatch,
                'ledger_mismatch' => (bool) $virtualOpening,
                'display_resolved' => $virtualOpening ? true : (!$isMismatch && !$hasInferredGenericAllocations && !$hasUnallocatedGenericPayments),
                'display_balance_target' => $storedBalance,
                'display_balance_final' => $documentFinalBalance,
                'excluded_ledger_entries' => $excludedLedgerEntries,
            ]
        ];
    }

    private function inferGenericSupplierPaymentCoverage(
        Collection $purchases,
        Collection $genericPayments,
        array $directCoverageByPurchase
    ): array {
        $diagnostics = [
            'policy' => 'inferred_fifo_projection_only',
            'has_inferred_allocations' => false,
            'has_unallocated_generic_payments' => false,
            'inferred_allocations' => [],
            'unallocated_generic_payments' => [],
            'warnings' => [],
            'note' => 'Generic SupplierPayment rows do not persist purchase-level allocation. Coverage is inferred only to avoid duplicate TTNH fallback in the display timeline; it is not actual allocation evidence.',
        ];

        if ($genericPayments->isEmpty()) {
            return [
                'coverage' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $purchaseStates = $purchases
            ->filter(fn (Purchase $purchase) => (float) $purchase->paid_amount > 0.01)
            ->filter(fn (Purchase $purchase) => (string) ($purchase->status ?? '') === 'completed')
            ->sort(function (Purchase $a, Purchase $b) {
                $timeCompare = strcmp(
                    $this->normalizeSortableTime($a->purchase_date ?: $a->created_at),
                    $this->normalizeSortableTime($b->purchase_date ?: $b->created_at)
                );

                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                return ((int) $a->id) <=> ((int) $b->id);
            })
            ->map(function (Purchase $purchase) use ($directCoverageByPurchase) {
                $paidAmount = (float) $purchase->paid_amount;
                $directCovered = max(0.0, (float) ($directCoverageByPurchase[$purchase->code] ?? 0.0));

                return [
                    'code' => (string) $purchase->code,
                    'sort_time' => $this->normalizeSortableTime($purchase->purchase_date ?: $purchase->created_at),
                    'remaining_paid_amount' => max(0.0, $paidAmount - $directCovered),
                ];
            })
            ->filter(fn (array $state) => $state['remaining_paid_amount'] > 0.01)
            ->values()
            ->all();

        if (empty($purchaseStates)) {
            return [
                'coverage' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $coverage = [];
        $payments = $genericPayments
            ->filter(fn (CashFlow $cashFlow) => (float) $cashFlow->amount > 0.01)
            ->sort(function (CashFlow $a, CashFlow $b) {
                $timeCompare = strcmp(
                    $this->normalizeSortableTime($a->time ?: $a->created_at),
                    $this->normalizeSortableTime($b->time ?: $b->created_at)
                );

                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                return ((int) $a->id) <=> ((int) $b->id);
            })
            ->values();

        foreach ($payments as $payment) {
            $remainingPayment = (float) $payment->amount;
            $paymentTime = $this->normalizeSortableTime($payment->time ?: $payment->created_at);

            foreach ($purchaseStates as $index => $state) {
                if ($remainingPayment <= 0.01) {
                    break;
                }

                if ($state['remaining_paid_amount'] <= 0.01) {
                    continue;
                }

                if ($paymentTime !== '' && (string) $state['sort_time'] > $paymentTime) {
                    continue;
                }

                $allocated = min($remainingPayment, $state['remaining_paid_amount']);
                $purchaseCode = $state['code'];

                $coverage[$purchaseCode] = (float) ($coverage[$purchaseCode] ?? 0.0) + $allocated;
                $purchaseStates[$index]['remaining_paid_amount'] -= $allocated;
                $remainingPayment -= $allocated;

                $diagnostics['has_inferred_allocations'] = true;
                $diagnostics['inferred_allocations'][] = [
                    'payment_code' => $payment->code,
                    'purchase_code' => $purchaseCode,
                    'amount' => $allocated,
                    'allocation_confidence' => 'inferred',
                    'allocation_is_actual' => false,
                    'evidence' => 'purchase_paid_amount_snapshot_without_persisted_supplier_payment_allocation',
                ];
            }

            if ($remainingPayment > 0.01) {
                $diagnostics['has_unallocated_generic_payments'] = true;
                $diagnostics['unallocated_generic_payments'][] = [
                    'payment_code' => $payment->code,
                    'amount' => $remainingPayment,
                    'allocation_confidence' => 'unknown',
                    'allocation_is_actual' => false,
                    'reason' => 'no_eligible_purchase_paid_snapshot_at_or_before_payment_time',
                ];
            }
        }

        if ($diagnostics['has_inferred_allocations']) {
            $diagnostics['warnings'][] = 'generic_supplier_payment_allocation_is_inferred_not_actual';
        }

        if ($diagnostics['has_unallocated_generic_payments']) {
            $diagnostics['warnings'][] = 'generic_supplier_payment_has_unallocated_residual';
        }

        return [
            'coverage' => $coverage,
            'diagnostics' => $diagnostics,
        ];
    }

    private function classifySupplierDebt(SupplierDebtTransaction $stx): array
    {
        $type = (string) $stx->type;
        $refCode = (string) ($stx->code ?? '');
        $amount = (float) $stx->amount;

        $typeLabels = [
            'adjustment' => 'Điều chỉnh',
            'discount' => 'Chiết khấu TT',
            'offset' => 'Điều chỉnh',
        ];

        $displayType = $typeLabels[$type] ?? ucfirst($type);
        $eventKind = 'supplier_' . $type;
        $badgeLabel = $typeLabels[$type] ?? ucfirst($type);

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

    private function classifyCustomerDebt(CustomerDebt $debt): array
    {
        $type = (string) $debt->type;
        $refCode = (string) ($debt->ref_code ?? '');
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
        if ($type === 'sale_reversal') {
            return ['Hủy hóa đơn', 'invoice_cancel', 'Ledger'];
        }
        if ($type === 'adjustment') {
            if (str_starts_with($refCode, 'MERGE') || str_starts_with($refCode, 'OPENING-BALANCE')) {
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

    private function createEntry(array $data): array
    {
        return array_merge([
            'id' => null,
            'code' => null,
            'display_type' => null,
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
            'supplier_display_effect' => 0.0,
            'affects_document_balance' => true,
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
            'display_sequence' => $this->getDisplaySequence($data),
        ], $data);
    }

    private function withCompatibilityAliases(array $entry): array
    {
        $effect = (float) ($entry['supplier_display_effect'] ?? $entry['display_effect'] ?? $entry['amount'] ?? 0.0);
        $displayBalanceEffect = (float) ($entry['supplier_display_balance_effect'] ?? $effect);
        $balanceEffect = (float) ($entry['supplier_balance_effect'] ?? $displayBalanceEffect);
        $running = (float) ($entry['supplier_display_running_balance'] ?? $entry['running_balance'] ?? 0.0);

        $entry['supplier_effect'] = $effect;
        $entry['supplier_display_balance_effect'] = $displayBalanceEffect;
        $entry['supplier_balance_effect'] = $balanceEffect;
        $entry['debt_remain'] = $running;
        $entry['type_label'] = $entry['type_label']
            ?? $entry['display_type']
            ?? $entry['badge_label']
            ?? $this->compatibilityTypeLabel($entry);
        $entry['type'] = $entry['type'] ?? $this->compatibilityType($entry);
        $entry['source_ledger'] = $entry['source_ledger'] ?? $this->compatibilitySourceLedger($entry);
        $entry['partner_effect'] = $entry['partner_effect'] ?? $effect;
        $entry['supplier_partner_effect'] = $entry['supplier_partner_effect'] ?? $effect;
        $entry['partner_running_balance'] = $entry['partner_running_balance'] ?? $running;
        $entry['supplier_partner_running_balance'] = $entry['supplier_partner_running_balance'] ?? $running;
        $entry['affects_debt_balance'] = $entry['affects_debt_balance'] ?? ! (bool) (
            $entry['reference_only']
            ?? $entry['is_reference_only']
            ?? false
        );
        $entry['created_at'] = $this->compatibilityCreatedAt($entry);

        return $entry;
    }

    private function normalizeSupplierPartnerDisplayAliases(array $entry): array
    {
        $effect = (float) ($entry['supplier_display_effect'] ?? $entry['display_effect'] ?? $entry['amount'] ?? 0.0);
        $entry['supplier_display_effect'] = $effect;
        $entry['supplier_display_balance_effect'] = (float) ($entry['supplier_display_balance_effect'] ?? $effect);

        $eventKind = (string) ($entry['event_kind'] ?? '');
        $referenceType = (string) ($entry['reference_type'] ?? '');
        $domain = (string) ($entry['domain'] ?? '');
        $isCustomerDocumentReference = $domain === 'customer'
            && in_array($eventKind, [
                'customer_sale',
                'invoice_payment',
                'invoice_payment_fallback',
                'sales_return',
                'refund',
            ], true)
            && in_array($referenceType, ['Invoice', 'OrderReturn', 'CashFlow'], true);

        if ($isCustomerDocumentReference) {
            $entry['supplier_balance_effect'] = 0.0;
            $entry['affects_debt_balance'] = false;
            $entry['reference_only'] = true;
            $entry['is_reference_only'] = true;
            $entry['badge_label'] = 'Phải thu KH';
        } else {
            $entry['supplier_balance_effect'] = (float) ($entry['supplier_balance_effect'] ?? $entry['supplier_display_balance_effect']);
            $entry['affects_debt_balance'] = $entry['affects_debt_balance'] ?? true;
        }

        $entry['partner_effect'] = $entry['partner_effect'] ?? $effect;
        $entry['supplier_partner_effect'] = $entry['supplier_partner_effect'] ?? $effect;
        $entry['source_ledger'] = $entry['source_ledger'] ?? $this->compatibilitySourceLedger($entry);

        return $entry;
    }

    private function shiftSupplierDisplayRunningAliases(array $entry, float $amount): array
    {
        foreach ([
            'supplier_display_running_balance',
            'supplier_running_balance',
            'running_balance',
            'partner_running_balance',
            'supplier_partner_running_balance',
            'debt_remain',
            'balance',
        ] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null && $entry[$key] !== '') {
                $entry[$key] = (float) $entry[$key] + $amount;
            }
        }

        return $entry;
    }

    private function virtualOpeningTime(Collection $entries): Carbon
    {
        $first = $entries
            ->map(fn ($entry) => is_array($entry) ? $entry : (array) $entry)
            ->filter(fn ($entry) => !empty($entry['time'] ?? $entry['display_time'] ?? $entry['created_at'] ?? null))
            ->sortBy(fn ($entry) => $this->normalizeSortableTime($entry['time'] ?? $entry['display_time'] ?? $entry['created_at'] ?? null))
            ->first();

        $value = $first['time'] ?? $first['display_time'] ?? $first['created_at'] ?? null;

        try {
            return $value ? Carbon::parse($value)->subSecond() : Carbon::now()->startOfDay();
        } catch (\Throwable) {
            return Carbon::now()->startOfDay();
        }
    }

    private function compatibilityType(array $entry): string
    {
        return match ((string) ($entry['event_kind'] ?? '')) {
            'purchase' => 'purchase',
            'supplier_payment', 'supplier_payment_fallback' => 'payment',
            'purchase_return' => 'return',
            'debt_offset' => 'offset',
            'debt_offset_cancel' => 'offset_cancel',
            'customer_sale' => 'customer_sale',
            'invoice_payment', 'invoice_payment_fallback' => 'customer_payment',
            'sales_return' => 'sales_return',
            'refund' => 'refund',
            'payment_discount' => 'discount',
            default => str_contains((string) ($entry['event_kind'] ?? ''), 'adjustment') ? 'adjustment' : 'document',
        };
    }

    private function compatibilityTypeLabel(array $entry): string
    {
        return match ($this->compatibilityType($entry)) {
            'purchase' => 'Nhập hàng',
            'payment' => 'Thanh toán NCC',
            'return' => 'Trả hàng nhập',
            'offset', 'adjustment' => 'Điều chỉnh',
            'customer_sale' => 'Bán hàng',
            'customer_payment' => 'Khách thanh toán',
            'sales_return' => 'Trả hàng bán',
            'refund' => 'Hoàn tiền khách',
            'discount' => 'Chiết khấu TT',
            default => '',
        };
    }

    private function compatibilitySourceLedger(array $entry): string
    {
        return match ((string) ($entry['domain'] ?? '')) {
            'customer' => 'customer_receivable',
            'supplier', 'adjustment' => 'supplier_payable',
            default => (string) ($entry['source'] ?? 'document_first'),
        };
    }

    private function compatibilityCreatedAt(array $entry)
    {
        $createdAt = $entry['created_at'] ?? null;
        $displayTime = $entry['display_time'] ?? $entry['time'] ?? null;

        if (!$createdAt || !$displayTime) {
            return $createdAt;
        }

        try {
            $created = $createdAt instanceof Carbon ? $createdAt : Carbon::parse($createdAt);
            $display = $displayTime instanceof Carbon ? $displayTime : Carbon::parse($displayTime);
        } catch (\Throwable) {
            return $createdAt;
        }

        if ($created->greaterThan($display) && abs($created->diffInSeconds(Carbon::now())) <= 300) {
            return $display->toDateTimeString();
        }

        return $createdAt;
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
        if (!$code) {
            return false;
        }

        return str_starts_with($code, 'MERGE-CUSTOMER-')
            || str_starts_with($code, 'MERGE-SUPPLIER-')
            || str_starts_with($code, 'MERGE-PARTNER-')
            || str_starts_with($code, 'OPENING-BALANCE-')
            || str_starts_with($code, 'OPENING-BALANCE-SUPPLIER-');
    }

    private function normalizeSortableTime($value): string
    {
        if (!$value) {
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
