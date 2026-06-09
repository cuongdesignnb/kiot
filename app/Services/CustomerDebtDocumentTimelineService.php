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

class CustomerDebtDocumentTimelineService
{
    public function build(Customer $customer, array $options = []): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) ($customer->is_customer && ($hasSupplierColumn ? $customer->is_supplier : false));

        $entries = collect();
        $purchases = collect();
        $excludedLedgerEntries = [];
        $includeTechnical = (bool) ($options['include_technical'] ?? $options['audit'] ?? false);

        // 1. Invoices
        $invoices = Invoice::where('customer_id', $customer->id)
            ->where(function($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'Đã hủy');
            })
            ->get();

        $invoiceCodes = $invoices->pluck('code')->filter()->toArray();

        foreach ($invoices as $invoice) {
            $businessTime = $invoice->transaction_date ?: $invoice->created_at;
            $entries->push($this->createEntry([
                'id' => 'invoice-' . $invoice->id,
                'code' => $invoice->code,
                'display_type' => 'Bán hàng',
                'event_kind' => 'customer_sale',
                'domain' => 'customer',
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
                'debug' => [
                    'document_source' => 'invoices',
                    'invoice_total' => (float) $invoice->total,
                    'invoice_customer_paid' => (float) $invoice->customer_paid,
                    'must_display_invoice_total' => true,
                ],
            ]));
        }

        // 2. Receipt CashFlows (both linked and standalone)
        $receipts = CashFlow::active()
            ->where('target_id', $customer->id)
            ->where('target_type', 'Khách hàng')
            ->where('type', 'receipt')
            ->get();

        // Group receipts by invoice code if linked
        $receiptsByInvoice = [];
        $standaloneReceipts = [];

        foreach ($receipts as $cf) {
            $refCode = $cf->reference_code;
            if ($cf->reference_type === 'Invoice' && $refCode && in_array($refCode, $invoiceCodes, true)) {
                $receiptsByInvoice[$refCode][] = $cf;
            } else {
                $standaloneReceipts[] = $cf;
            }
        }

        // Emit linked receipts
        foreach ($receiptsByInvoice as $refCode => $cfs) {
            $invoice = $invoices->firstWhere('code', $refCode);
            $invoicePaid = $invoice ? (float) $invoice->customer_paid : 0.0;
            $receiptTotal = (float) collect($cfs)->sum('amount');
            $mismatch = abs($receiptTotal - $invoicePaid) > 0.01;

            foreach ($cfs as $cf) {
                $businessTime = $cf->time ?: $cf->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cash_flow-' . $cf->id,
                    'code' => $cf->code,
                    'display_type' => 'Thanh toán hóa đơn',
                    'event_kind' => 'invoice_payment',
                    'domain' => 'customer',
                    'document_amount' => (float) $cf->amount,
                    'amount' => (float) $cf->amount,
                    'display_effect' => -(float) $cf->amount,
                    'customer_display_effect' => -(float) $cf->amount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => 'InvoicePayment',
                    'reference_id' => $invoice ? $invoice->id : null,
                    'reference_code' => $refCode,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cf->id,
                    'detail_reference_code' => $cf->code,
                    'badge_label' => $mismatch ? 'Cần đối soát' : 'Thanh toán',
                    'badge_title' => $mismatch ? 'Tổng phiếu thu thật không khớp số đã thanh toán trên hóa đơn.' : null,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'receipt_allocation_mismatch' => $mismatch,
                    'needs_manual_review' => $mismatch,
                    'source' => 'document_first',
                ]));
            }
        }

        // 3. Fallback Payment from invoice.customer_paid
        foreach ($invoices as $invoice) {
            if ((float) $invoice->customer_paid > 0) {
                $hasRealReceipt = isset($receiptsByInvoice[$invoice->code]) && count($receiptsByInvoice[$invoice->code]) > 0;
                if (!$hasRealReceipt) {
                    $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                    $entries->push($this->createEntry([
                        'id' => 'invpay-fallback-' . $invoice->id,
                        'code' => 'TTHD' . preg_replace('/^HD/', '', $invoice->code),
                        'display_type' => 'Thanh toán hóa đơn',
                        'event_kind' => 'invoice_payment', // wait, must be invoice_payment for display sequencing
                        'domain' => 'customer',
                        'document_amount' => (float) $invoice->customer_paid,
                        'amount' => (float) $invoice->customer_paid,
                        'display_effect' => -(float) $invoice->customer_paid,
                        'customer_display_effect' => -(float) $invoice->customer_paid,
                        'time' => $businessTime,
                        'display_time' => $businessTime,
                        'created_at' => $invoice->created_at,
                        'reference_type' => 'Invoice',
                        'reference_id' => $invoice->id,
                        'reference_code' => $invoice->code,
                        'is_virtual_fallback' => true,
                        'is_virtual_payment' => true,
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

        // 4. Standalone Receipts (including DebtAdjustment if it's type receipt)
        foreach ($standaloneReceipts as $cf) {
            $businessTime = $cf->time ?: $cf->created_at;
            $isAdjustment = $cf->reference_type === 'DebtAdjustment';
            
            $entries->push($this->createEntry([
                'id' => 'cash_flow-' . $cf->id,
                'code' => $cf->code,
                'display_type' => $isAdjustment ? 'Điều chỉnh công nợ' : 'Khách thanh toán',
                'event_kind' => $isAdjustment ? 'debt_adjustment' : 'customer_payment',
                'domain' => 'customer',
                'document_amount' => (float) $cf->amount,
                'amount' => (float) $cf->amount,
                'display_effect' => -(float) $cf->amount,
                'customer_display_effect' => -(float) $cf->amount,
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
                'source' => 'document_first',
            ]));
        }

        // 5. Payment CashFlows targeting Khách hàng (Refunds or DebtAdjustment if type payment)
        $payments = CashFlow::active()
            ->where('target_id', $customer->id)
            ->where('target_type', 'Khách hàng')
            ->where('type', 'payment')
            ->get();

        foreach ($payments as $cf) {
            $businessTime = $cf->time ?: $cf->created_at;
            $isAdjustment = $cf->reference_type === 'DebtAdjustment';
            
            $entries->push($this->createEntry([
                'id' => 'cash_flow-' . $cf->id,
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

        // 6. Sales Returns (OrderReturns)
        $returns = OrderReturn::where('customer_id', $customer->id)
            ->where(function($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'Đã hủy');
            })
            ->get();

        foreach ($returns as $return) {
            $businessTime = ($return->return_date ?? null) ?: $return->created_at;
            $entries->push($this->createEntry([
                'id' => 'return-' . $return->id,
                'code' => $return->code,
                'display_type' => 'Trả hàng bán',
                'event_kind' => 'sales_return',
                'domain' => 'customer',
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
                'source' => 'document_first',
            ]));

            // Synthesise virtual refund if paid_to_customer > 0
            if ((float) $return->paid_to_customer > 0) {
                $entries->push($this->createEntry([
                    'id' => 'refund-fallback-' . $return->id,
                    'code' => 'PCTH' . preg_replace('/^TH/', '', $return->code),
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
            }
        }

        $adjustmentDebts = CustomerDebt::where('customer_id', $customer->id)->get();
        $existingCodes = $entries->pluck('code')->filter()->toArray();

        foreach ($adjustmentDebts as $debt) {
            $refCode = $debt->ref_code;
            
            // Skip if this code has already been fetched via document-first
            if ($refCode && in_array($refCode, $existingCodes, true)) {
                continue;
            }

            $isTech = $this->isTechnicalLedgerCode($refCode, $customer);
            if ($isTech) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $debt->amount,
                    'reason' => 'technical_ledger_excluded_from_document_timeline',
                ];
                if (!$includeTechnical) {
                    continue;
                }
            }

            $businessTime = $debt->recorded_at ?: $debt->created_at;
            [$displayType, $eventKind, $badgeLabel] = $this->classifyCustomerDebt($debt);

            $entries->push($this->createEntry([
                'id' => 'customer_debt-' . $debt->id,
                'code' => $refCode ?: ('DC' . $debt->id),
                'display_type' => $displayType,
                'event_kind' => $eventKind,
                'domain' => 'adjustment',
                'document_amount' => abs((float) $debt->amount),
                'amount' => (float) $debt->amount,
                'display_effect' => (float) $debt->amount,
                'customer_display_effect' => $isTech ? 0.0 : (float) $debt->amount,
                'affects_document_balance' => !$isTech,
                'excluded_from_document_balance' => $isTech,
                'excluded_reason' => $isTech ? 'technical_ledger_merge_or_opening' : null,
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
        $offsets = $isDualRole ? collect() : DebtOffset::where('customer_id', $customer->id)->get();
        foreach ($offsets as $offset) {
            $entries->push($this->createEntry([
                'id' => 'offset-' . $offset->id,
                'code' => $offset->code,
                'display_type' => 'Điều chỉnh',
                'event_kind' => 'debt_offset',
                'domain' => 'customer',
                'document_amount' => (float) $offset->amount,
                'amount' => (float) $offset->amount,
                'display_effect' => -(float) $offset->amount,
                'customer_display_effect' => -(float) $offset->amount,
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
        if ($isDualRole) {
            // Purchases
            $purchases = Purchase::where('supplier_id', $customer->id)
                ->where('status', '!=', 'cancelled')
                ->get();
            $purchaseCodes = $purchases->pluck('code')->filter()->toArray();

            foreach ($purchases as $p) {
                $businessTime = $p->purchase_date ?: $p->created_at;
                $entries->push($this->createEntry([
                    'id' => 'sup-purchase-' . $p->id,
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
                           ->whereIn('target_type', ['Nha cung cap', 'Nhà cung cấp']);
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
                    'id' => 'sup-payment-' . $cf->id,
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
                        if ($cf->code === 'PCPN' . preg_replace('/^PN/', '', $p->code) || $cf->code === 'TTNH' . preg_replace('/^PN/', '', $p->code)) {
                            $hasRealPayment = true;
                            break;
                        }
                    }
                    if (!$hasRealPayment) {
                        $businessTime = $p->purchase_date ?: $p->created_at;
                        $entries->push($this->createEntry([
                            'id' => 'sup-purpay-fallback-' . $p->id,
                            'code' => 'TTNH' . preg_replace('/^PN/', '', $p->code),
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
                    'id' => 'sup-return-' . $pr->id,
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
                ->get();

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
                    'id' => 'sup-stx-' . $stx->id,
                    'code' => $stx->code,
                    'display_type' => $typeLabels[$stx->type] ?? $stx->type,
                    'event_kind' => 'supplier_mirror_' . $stx->type,
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
            $supplierOffsets = DebtOffset::where('customer_id', $customer->id)->get();
            foreach ($supplierOffsets as $offset) {
                $entries->push($this->createEntry([
                    'id' => 'sup-offset-' . $offset->id,
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

                if ($offset->status === 'cancelled') {
                    $cancelCode = 'HCB' . str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                    $entries->push($this->createEntry([
                        'id' => 'sup-offset-cancel-' . $offset->id,
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

        // Dedup by non-null code: if we have a document-first entry and a ledger entry with the same code, prefer the document-first one.
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
                        // If both are ledger or both are document-first, keep both by using a unique key
                        $deduped[$code . '-' . $entry['id']] = $entry;
                    }
                }
            } else {
                $deduped[] = $entry;
            }
        }

        $entries = collect(array_values($deduped));

        // Add sorting group metadata to all entries
        $entries = $entries->map(function (array $entry) use ($invoices, $purchases) {
            $ownTime = $entry['display_time'] ?: $entry['time'] ?: $entry['created_at'];
            $ownTimeCarbon = $ownTime instanceof Carbon ? $ownTime : Carbon::parse($ownTime);
            
            $sortGroupTime = $ownTimeCarbon;
            $sortGroupKey = $entry['code'] ?: $entry['id'];
            $sortGroupSequence = (int) ($entry['display_sequence'] ?? 50);

            $refCode = $entry['reference_code'] ?? null;
            $eventKind = $entry['event_kind'] ?? '';
            
            // If it's a payment receipt or virtual payment belonging to an invoice:
            if (in_array($eventKind, ['invoice_payment', 'invoice_payment_fallback'], true) && $refCode) {
                $parentInvoice = $invoices->firstWhere('code', $refCode);
                if ($parentInvoice) {
                    $parentTime = $parentInvoice->transaction_date ?: $parentInvoice->created_at;
                    $sortGroupTime = $parentTime instanceof Carbon ? $parentTime : Carbon::parse($parentTime);
                    $sortGroupKey = $parentInvoice->code;
                }
            }
            
            // If it's a supplier payment belonging to a purchase:
            if (in_array($eventKind, ['supplier_payment', 'supplier_payment_fallback'], true) && $refCode) {
                $parentPurchase = $purchases->firstWhere('code', $refCode);
                if ($parentPurchase) {
                    $parentTime = $parentPurchase->purchase_date ?: $parentPurchase->created_at;
                    $sortGroupTime = $parentTime instanceof Carbon ? $parentTime : Carbon::parse($parentTime);
                    $sortGroupKey = $parentPurchase->code;
                }
            }

            $entry['sort_group_time'] = $sortGroupTime->toIso8601String();
            $entry['sort_group_key'] = (string) $sortGroupKey;
            $entry['sort_group_sequence'] = $sortGroupSequence;
            
            return $entry;
        });

        // Sort ASC for running balance calculation
        $sortedAsc = $entries->sort(function ($a, $b) {
            $compGroupTime = strcmp((string)($a['sort_group_time'] ?? ''), (string)($b['sort_group_time'] ?? ''));
            if ($compGroupTime !== 0) {
                return $compGroupTime;
            }
            
            $compGroupKey = strcmp((string)($a['sort_group_key'] ?? ''), (string)($b['sort_group_key'] ?? ''));
            if ($compGroupKey !== 0) {
                return $compGroupKey;
            }
            
            $compSeq = ((int)($a['sort_group_sequence'] ?? 999)) <=> ((int)($b['sort_group_sequence'] ?? 999));
            if ($compSeq !== 0) {
                return $compSeq;
            }

            $timeA = $a['display_time'] instanceof Carbon ? $a['display_time'] : Carbon::parse($a['display_time']);
            $timeB = $b['display_time'] instanceof Carbon ? $b['display_time'] : Carbon::parse($b['display_time']);
            $compTime = $timeA->timestamp <=> $timeB->timestamp;
            if ($compTime !== 0) {
                return $compTime;
            }
            
            return strcmp((string)$a['id'], (string)$b['id']);
        })->values();

        // Calculate chronological running balance
        $running = 0.0;
        $sorted = $sortedAsc->map(function (array $entry) use (&$running) {
            $effect = (float) ($entry['customer_display_effect']
                ?? $entry['display_effect']
                ?? $entry['amount']
                ?? 0);

            $affects = (bool) ($entry['affects_document_balance'] ?? $entry['affects_debt_balance'] ?? true);

            if ($affects) {
                $running += $effect;
            }

            $entry['customer_display_effect'] = $effect;
            $entry['display_effect'] = (float) ($entry['display_effect'] ?? $effect);
            $entry['customer_display_running_balance'] = $running;
            $entry['running_balance'] = $running;

            return $entry;
        });

        $documentFinalBalance = $running;

        // Sort DESC for display
        $displayEntries = $sorted->sort(function ($a, $b) {
            $groupTimeCompare = strcmp((string)($b['sort_group_time'] ?? ''), (string)($a['sort_group_time'] ?? ''));

            if ($groupTimeCompare !== 0) {
                return $groupTimeCompare;
            }

            $groupKeyCompare = strcmp((string)($b['sort_group_key'] ?? ''), (string)($a['sort_group_key'] ?? ''));

            if ($groupKeyCompare !== 0) {
                return $groupKeyCompare;
            }

            $compSeq = ((int)($a['sort_group_sequence'] ?? 999)) <=> ((int)($b['sort_group_sequence'] ?? 999));
            if ($compSeq !== 0) {
                return $compSeq;
            }

            $timeA = $a['display_time'] instanceof Carbon ? $a['display_time'] : Carbon::parse($a['display_time']);
            $timeB = $b['display_time'] instanceof Carbon ? $b['display_time'] : Carbon::parse($b['display_time']);
            $compTime = $timeA->timestamp <=> $timeB->timestamp;
            if ($compTime !== 0) {
                return $compTime;
            }
            
            return strcmp((string)$b['id'], (string)$a['id']);
        })->values();

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

        // Stored balances & reconciliation
        $storedCustomerDebt = (float) ($customer->debt_amount ?? 0);
        $storedSupplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $storedNet = $storedCustomerDebt - $storedSupplierDebt;

        $difference = $documentFinalBalance - $storedNet;
        $isMismatch = abs($difference) > 1.0;

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
                'document_final_balance' => $documentFinalBalance,
                'is_dual_role' => $isDualRole,
                'mode' => 'document_first',
                'count' => $displayEntries->count(),
                // Alignment keys
                'customer_debt_amount' => $storedCustomerDebt,
                'supplier_debt_amount' => $storedSupplierDebt,
                'net_debt_amount' => $storedNet,
                'net' => $storedNet,
                'display_balance_target' => $storedNet,
                'display_balance_final' => $documentFinalBalance,
            ],
            'reconcile' => [
                'severity' => $severity,
                'message' => $message,
                'user_warning' => $isMismatch,
                'stored_balance' => $storedNet,
                'document_balance' => $documentFinalBalance,
                'difference' => $difference,
                // Alignment keys
                'computed_balance' => $storedNet,
                'has_mismatch' => $isMismatch,
                'ledger_mismatch' => false,
                'display_resolved' => !$isMismatch,
                'display_balance_target' => $storedNet,
                'display_balance_final' => $documentFinalBalance,
                'excluded_ledger_entries' => $excludedLedgerEntries,
            ]
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
        if ($type === 'sale_reversal') {
            return ['Hủy hóa đơn', 'invoice_cancel', 'Ledger'];
        }
        if ($type === 'adjustment') {
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

    private function createEntry(array $data): array
    {
        return array_merge([
            'id' => null,
            'code' => null,
            'display_type' => null,
            'event_kind' => null,
            'domain' => null,
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
            'display_sequence' => $this->getDisplaySequence($data),
        ], $data);
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

    private function isTechnicalLedgerCode(?string $code, Customer $customer): bool
    {
        if (!$code) {
            return false;
        }

        $isTechPrefix = str_starts_with($code, 'MERGE-CUSTOMER-')
            || str_starts_with($code, 'MERGE-SUPPLIER-')
            || str_starts_with($code, 'OPENING-BALANCE-')
            || str_starts_with($code, 'OPENING-BALANCE-SUPPLIER-');

        if (!$isTechPrefix) {
            return false;
        }

        // Only exclude if actual documents are present in this domain to prevent double-counting.
        // For customer domain technical codes, check if real invoices exist:
        if (str_contains($code, 'CUSTOMER')) {
            return Invoice::where('customer_id', $customer->id)
                ->where(function($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'Đã hủy');
                })
                ->exists();
        }

        // For supplier domain technical codes, check if real purchases exist:
        if (str_contains($code, 'SUPPLIER')) {
            return Purchase::where('supplier_id', $customer->id)
                ->where('status', '!=', 'cancelled')
                ->exists();
        }

        return true;
    }
}
