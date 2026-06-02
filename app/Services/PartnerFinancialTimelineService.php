<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentDiscount;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Models\DebtOffset;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PartnerFinancialTimelineService
{
    public function buildForCustomer(Customer $customer): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) ($customer->is_customer && ($hasSupplierColumn ? $customer->is_supplier : false));

        $customerDebts = CustomerDebt::query()
            ->where('customer_id', $customer->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $hasCustomerLedger = $customerDebts->isNotEmpty();
        [$ledgerEntries, $ledgerEntriesRaw] = $this->buildCustomerLedgerEntries($customerDebts);

        $legacyEntries = $hasCustomerLedger
            ? $this->buildCustomerLegacyReferenceEntries($customer, $customerDebts)
            : $this->buildCustomerLegacyAffectingEntries($customer);

        $supplierEntries = $isDualRole
            ? $this->buildSupplierDocumentEntriesForCustomerScreen($customer)
                ->concat($this->buildSupplierReferenceEntries($customer))
                ->values()
            : collect();

        $ledgerCodes = $ledgerEntries->pluck('code')->filter()->map(fn ($code) => (string) $code)->all();
        $legacyFiltered = $legacyEntries
            ->filter(fn ($entry) => !in_array((string) ($entry['code'] ?? ''), $ledgerCodes, true))
            ->values();

        $combined = $ledgerEntries
            ->concat($legacyFiltered)
            ->concat($supplierEntries)
            ->values();

        $customerDebt = (float) ($customer->debt_amount ?? 0);
        $supplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $netDebt = $customerDebt - $supplierDebt;
        $combined = $this->injectCustomerVirtualOpeningBalance($combined, $customer, $netDebt);
        [$computedEntries, $computedBalance] = $this->computeRunningBalance($combined);
        $hasMismatch = abs($computedBalance - $netDebt) >= 0.01;
        $displayFinalBalance = (float) ($computedEntries->sortBy(fn ($entry) => $this->timestamp($entry))->last()['customer_display_running_balance'] ?? 0.0);
        $virtualOpening = $computedEntries->firstWhere('is_virtual_opening', true);

        return [
            'entries' => $computedEntries->sortByDesc(fn ($entry) => $this->timestamp($entry))->values(),
            'ledger_entries' => $ledgerEntriesRaw->sortByDesc(fn ($entry) => $this->timestamp($entry))->values(),
            'legacy_entries' => $legacyEntries->sortByDesc(fn ($entry) => $this->timestamp($entry))->values(),
            'reconcile' => [
                'current_net_debt' => $netDebt,
                'computed_balance' => $computedBalance,
                'has_mismatch' => $hasMismatch,
                'message' => $hasMismatch
                    ? 'Lịch sử công nợ đang lệch với Nợ hiện tại. Cần đối soát dữ liệu trước khi cập nhật.'
                    : null,
            ],
            'summary' => array_merge([
                // Canonical receivable/payable/net keys (HOTFIX FOLLOW-UP)
                'customer_receivable_balance' => $customerDebt,
                'supplier_payable_balance'    => $supplierDebt,
                'partner_net_position'        => $netDebt,
                'has_debt_offset_voucher'     => $this->hasActiveDebtOffsetVoucher($customer),
                'is_actual_offset'            => false,
                'is_net_view'                 => true,
                'display_timeline_mode'        => true,
                'has_virtual_opening_balance'  => (bool) $virtualOpening,
                'virtual_opening_balance'      => (float) ($virtualOpening['customer_display_effect'] ?? 0.0),
                'display_balance_target'       => $netDebt,
                'display_balance_final'        => $displayFinalBalance,

                // Backward-compatible keys (FE + existing tests still read these)
                'net' => $netDebt,
                'current_debt' => $netDebt,
                'customer_debt_amount' => $customerDebt,
                'supplier_debt_amount' => $supplierDebt,
                'net_debt_amount' => $netDebt,
                'net_debt_direction' => $netDebt > 0
                    ? 'customer_owes_store'
                    : ($netDebt < 0 ? 'store_owes_customer_supplier' : 'settled'),
                'is_dual_role' => $isDualRole,
                'source' => 'partner_financial_timeline',
                'count' => $computedEntries->count(),
                'ledger_count' => $ledgerEntriesRaw->count(),
                'legacy_count' => $legacyEntries->count(),
                'supplier_count' => $supplierEntries->count(),
                'dedup_skipped' => $legacyEntries->count() - $legacyFiltered->count(),
            ], []),
        ];
    }

    /**
     * Returns true iff there is a non-cancelled DebtOffset voucher
     * (CB/HCB) that names this customer or supplier. Used to surface
     * `has_debt_offset_voucher` in the summary so the UI can stop
     * implying "đã đối trừ" when only a display delta exists.
     */
    private function hasActiveDebtOffsetVoucher(Customer $partner): bool
    {
        // DebtOffset only stores customer_id — dual-role partners share
        // a single customer record across receivable + payable sides.
        return DebtOffset::query()
            ->where('customer_id', $partner->id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    private function buildCustomerLedgerEntries(Collection $debts): array
    {
        $discountCodes = $debts->pluck('ref_code')
            ->filter(fn ($code) => str_starts_with((string) $code, 'CKTT'))
            ->values();

        $discountsByCode = CustomerPaymentDiscount::whereIn('code', $discountCodes)->get()->keyBy('code');

        [$matchedSettlementIds, $returnSettlementMeta] = $this->buildReturnSettlementMeta($debts);

        $raw = $debts
            ->map(fn ($debt) => $this->mapCustomerDebt($debt, [], $discountsByCode))
            ->values();

        $display = $debts
            ->reject(fn ($debt) => $this->isAutoReturnSettlement($debt) && in_array($debt->id, $matchedSettlementIds, true))
            ->map(fn ($debt) => $this->mapCustomerDebt($debt, $returnSettlementMeta, $discountsByCode))
            ->values();

        return [$display, $raw];
    }

    private function buildCustomerLegacyReferenceEntries(Customer $customer, Collection $customerDebts): Collection
    {
        return $this->buildCustomerLegacyEntries($customer, true, $customerDebts);
    }

    private function buildCustomerLegacyAffectingEntries(Customer $customer): Collection
    {
        return $this->buildCustomerLegacyEntries($customer, false, collect());
    }

    private function buildCustomerLegacyEntries(Customer $customer, bool $hasCustomerLedger, Collection $customerDebts): Collection
    {
        $entries = collect();

        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get(['id', 'code', 'total', 'customer_paid', 'status', 'created_at', 'transaction_date']);

        foreach ($invoices as $invoice) {
            if ($this->isCancelledStatus($invoice->status)) {
                continue;
            }

            $entries->push($this->entry([
                'id' => 'inv-' . $invoice->id,
                'code' => $invoice->code,
                'display_type' => 'Bán hàng',
                'event_kind' => 'customer_sale',
                'domain' => 'customer',
                'document_amount' => (float) $invoice->total,
                'amount' => (float) $invoice->total,
                'display_effect' => (float) $invoice->total,
                'financial_effect' => (float) $invoice->total,
                'balance_effect' => $hasCustomerLedger ? 0.0 : (float) $invoice->total,
                'customer_display_effect' => (float) $invoice->total,
                'customer_balance_effect' => $hasCustomerLedger ? 0.0 : (float) $invoice->total,
                'customer_effect' => $hasCustomerLedger ? 0.0 : (float) $invoice->total,
                'affects_debt_balance' => !$hasCustomerLedger,
                'is_reference_only' => $hasCustomerLedger,
                'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                'badge_label' => $hasCustomerLedger ? 'Phải thu KH' : 'Chứng từ cũ',
                'badge_title' => $hasCustomerLedger ? 'Chứng từ tham chiếu, không cộng lại số dư công nợ.' : null,
                'balance_note' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
                'time' => $invoice->transaction_date ?? $invoice->created_at,
                'created_at' => $invoice->created_at,
                'reference_type' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_code' => $invoice->code,
                'detail_available' => true,
            ]));

            if ((float) $invoice->customer_paid > 0) {
                $entries->push($this->entry([
                    'id' => 'invpay-' . $invoice->id,
                    'code' => 'TTHD' . preg_replace('/^HD/', '', (string) $invoice->code),
                    'display_type' => 'Thanh toán hóa đơn',
                    'event_kind' => 'invoice_payment',
                    'domain' => 'customer',
                    'document_amount' => (float) $invoice->customer_paid,
                    'amount' => (float) $invoice->customer_paid,
                    'display_effect' => -(float) $invoice->customer_paid,
                    'financial_effect' => -(float) $invoice->customer_paid,
                    'balance_effect' => $hasCustomerLedger ? 0.0 : -(float) $invoice->customer_paid,
                    'customer_display_effect' => -(float) $invoice->customer_paid,
                    'customer_balance_effect' => $hasCustomerLedger ? 0.0 : -(float) $invoice->customer_paid,
                    'customer_effect' => $hasCustomerLedger ? 0.0 : -(float) $invoice->customer_paid,
                    'affects_debt_balance' => !$hasCustomerLedger,
                    'is_reference_only' => $hasCustomerLedger,
                    'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                    'badge_label' => 'Thanh toán',
                    'badge_title' => $hasCustomerLedger ? 'Chứng từ tham chiếu, không cộng lại số dư công nợ.' : null,
                    'balance_note' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
                    'time' => $invoice->transaction_date ?? $invoice->created_at,
                    'created_at' => $invoice->created_at,
                    'reference_type' => 'InvoicePayment',
                    'reference_id' => $invoice->id,
                    'reference_code' => $invoice->code,
                    'is_virtual_payment' => true,
                    'detail_available' => true,
                ]));
            }
        }

        $entries = $entries->concat($this->buildOrderReturnEntries($customer, $hasCustomerLedger, $customerDebts))->values();
        $entries = $entries->concat($this->buildStandaloneCustomerCashFlowEntries($customer, $hasCustomerLedger, $invoices))->values();

        return $entries;
    }

    private function buildOrderReturnEntries(Customer $customer, bool $hasCustomerLedger, Collection $customerDebts): Collection
    {
        $entries = collect();
        $ledgerReturnCodes = $customerDebts
            ->filter(fn ($debt) => $debt->type === 'return')
            ->pluck('ref_code')
            ->filter()
            ->map(fn ($code) => (string) $code)
            ->all();
        $ledgerReturnIds = $customerDebts
            ->filter(fn ($debt) => $debt->type === 'return' && !empty($debt->order_return_id))
            ->pluck('order_return_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $returns = OrderReturn::query()
            ->where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get(['id', 'code', 'total', 'paid_to_customer', 'status', 'created_at']);

        foreach ($returns as $return) {
            if ($this->isCancelledStatus($return->status)) {
                continue;
            }

            $hasLedgerReturn = in_array((string) $return->code, $ledgerReturnCodes, true)
                || in_array((int) $return->id, $ledgerReturnIds, true);

            if ($hasLedgerReturn) {
                $entries->push($this->entry([
                    'id' => 'oret-ref-' . $return->id,
                    'code' => $return->code,
                    'display_type' => 'Trả hàng bán',
                    'event_kind' => 'sales_return',
                    'domain' => 'reference',
                    'document_amount' => (float) $return->total,
                    'amount' => (float) $return->total,
                    'display_effect' => -(float) $return->total,
                    'financial_effect' => -(float) $return->total,
                    'balance_effect' => 0.0,
                    'customer_display_effect' => -(float) $return->total,
                    'customer_balance_effect' => 0.0,
                    'customer_effect' => 0.0,
                    'affects_debt_balance' => false,
                    'is_reference_only' => true,
                    'source' => 'reference',
                    'badge_label' => 'Trả hàng',
                    'badge_title' => 'Chứng từ tham chiếu, không cộng lại số dư công nợ.',
                    'balance_note' => 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.',
                    'time' => $return->created_at,
                    'created_at' => $return->created_at,
                    'reference_type' => 'OrderReturn',
                    'reference_id' => $return->id,
                    'reference_code' => $return->code,
                    'detail_available' => true,
                ]));
                continue;
            }

            $affects = !$hasCustomerLedger;
            $entries->push($this->entry([
                'id' => 'oret-' . $return->id,
                'code' => $return->code,
                'display_type' => 'Trả hàng bán',
                'event_kind' => 'sales_return',
                'domain' => 'customer',
                'document_amount' => (float) $return->total,
                'amount' => (float) $return->total,
                'display_effect' => -(float) $return->total,
                'financial_effect' => -(float) $return->total,
                'balance_effect' => $affects ? -(float) $return->total : 0.0,
                'customer_display_effect' => -(float) $return->total,
                'customer_balance_effect' => $affects ? -(float) $return->total : 0.0,
                'customer_effect' => $affects ? -(float) $return->total : 0.0,
                'affects_debt_balance' => $affects,
                'is_reference_only' => !$affects,
                'source' => $affects ? 'legacy' : 'reference',
                'badge_label' => $affects ? 'Chứng từ cũ' : 'Cần đối soát',
                'badge_title' => $affects ? null : 'Có phiếu trả hàng nhưng chưa thấy ledger công nợ tương ứng',
                'balance_note' => $affects ? null : 'Cần đối soát: phiếu trả hàng chưa có dòng ledger tương ứng.',
                'time' => $return->created_at,
                'created_at' => $return->created_at,
                'reference_type' => 'OrderReturn',
                'reference_id' => $return->id,
                'reference_code' => $return->code,
                'detail_available' => true,
            ]));
        }

        return $entries;
    }

    private function buildStandaloneCustomerCashFlowEntries(Customer $customer, bool $hasCustomerLedger, Collection $invoices): Collection
    {
        $invoiceCodes = $invoices->pluck('code')->filter()->all();

        return CashFlow::query()
            ->where('target_type', 'Khách hàng')
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->whereNotIn('reference_type', ['DebtOffset', 'DebtOffsetCancel'])
            ->orderBy('created_at')
            ->get()
            ->filter(function ($cashFlow) use ($invoiceCodes) {
                return !($cashFlow->reference_type === 'Invoice' && in_array($cashFlow->reference_code, $invoiceCodes, true));
            })
            ->map(function ($cashFlow) use ($hasCustomerLedger) {
                return $this->entry([
                    'id' => 'cf-' . $cashFlow->id,
                    'code' => $cashFlow->code,
                    'display_type' => $cashFlow->reference_type === 'OrderReturn' ? 'Trả hàng bán' : 'Khách thanh toán',
                    'event_kind' => $cashFlow->reference_type === 'OrderReturn' ? 'sales_return' : 'customer_payment',
                    'domain' => 'customer',
                    'document_amount' => (float) $cashFlow->amount,
                    'amount' => (float) $cashFlow->amount,
                    'display_effect' => -(float) $cashFlow->amount,
                    'financial_effect' => -(float) $cashFlow->amount,
                    'balance_effect' => $hasCustomerLedger ? 0.0 : -(float) $cashFlow->amount,
                    'customer_display_effect' => -(float) $cashFlow->amount,
                    'customer_balance_effect' => $hasCustomerLedger ? 0.0 : -(float) $cashFlow->amount,
                    'customer_effect' => $hasCustomerLedger ? 0.0 : -(float) $cashFlow->amount,
                    'affects_debt_balance' => !$hasCustomerLedger,
                    'is_reference_only' => $hasCustomerLedger,
                    'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                    'badge_label' => $cashFlow->reference_type === 'OrderReturn' ? 'Trả hàng' : 'Thanh toán',
                    'badge_title' => $hasCustomerLedger ? 'Chứng từ tham chiếu, không cộng lại số dư công nợ.' : null,
                    'balance_note' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
                    'time' => $cashFlow->time ?? $cashFlow->created_at,
                    'created_at' => $cashFlow->created_at,
                    'reference_type' => $cashFlow->reference_type,
                    'reference_id' => $cashFlow->id,
                    'reference_code' => $cashFlow->reference_code,
                    'detail_available' => true,
                ]);
            })
            ->values();
    }

    private function buildSupplierDocumentEntriesForCustomerScreen(Customer $customer): Collection
    {
        $entries = collect();

        $purchases = Purchase::query()
            ->where('supplier_id', $customer->id)
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get(['id', 'code', 'total_amount', 'paid_amount', 'debt_amount', 'status', 'purchase_date', 'created_at']);

        foreach ($purchases as $purchase) {
            $entries->push($this->entry([
                'id' => 'pur-' . $purchase->id,
                'code' => $purchase->code,
                'display_type' => 'Nhập hàng',
                'event_kind' => 'supplier_purchase',
                'domain' => 'supplier',
                'document_amount' => (float) $purchase->total_amount,
                'amount' => (float) $purchase->total_amount,
                'display_effect' => -(float) $purchase->total_amount,
                'financial_effect' => -(float) $purchase->total_amount,
                'balance_effect' => -(float) $purchase->total_amount,
                'customer_display_effect' => -(float) $purchase->total_amount,
                'customer_balance_effect' => -(float) $purchase->total_amount,
                'supplier_display_effect' => (float) $purchase->total_amount,
                'supplier_balance_effect' => (float) $purchase->total_amount,
                'customer_effect' => -(float) $purchase->total_amount,
                'supplier_effect' => (float) $purchase->total_amount,
                'affects_debt_balance' => true,
                'source' => 'document',
                'badge_label' => 'Phiếu nhập',
                'time' => $purchase->purchase_date ?? $purchase->created_at,
                'created_at' => $purchase->created_at,
                'reference_type' => 'Purchase',
                'reference_id' => $purchase->id,
                'reference_code' => $purchase->code,
                'detail_available' => true,
            ]));

            if ((float) $purchase->paid_amount > 0) {
                $entries->push($this->entry([
                    'id' => 'purpay-' . $purchase->id,
                    'code' => 'TTNH' . preg_replace('/^PN/', '', (string) $purchase->code),
                    'display_type' => 'Thanh toán NCC',
                    'event_kind' => 'supplier_payment',
                    'domain' => 'supplier',
                    'document_amount' => (float) $purchase->paid_amount,
                    'amount' => (float) $purchase->paid_amount,
                    'display_effect' => (float) $purchase->paid_amount,
                    'financial_effect' => (float) $purchase->paid_amount,
                    'balance_effect' => (float) $purchase->paid_amount,
                    'customer_display_effect' => (float) $purchase->paid_amount,
                    'customer_balance_effect' => (float) $purchase->paid_amount,
                    'supplier_display_effect' => -(float) $purchase->paid_amount,
                    'supplier_balance_effect' => -(float) $purchase->paid_amount,
                    'customer_effect' => (float) $purchase->paid_amount,
                    'supplier_effect' => -(float) $purchase->paid_amount,
                    'affects_debt_balance' => true,
                    'source' => 'document',
                    'badge_label' => 'Thanh toán NCC',
                    'time' => $purchase->purchase_date ?? $purchase->created_at,
                    'created_at' => $purchase->created_at,
                    'reference_type' => 'PurchasePayment',
                    'reference_id' => $purchase->id,
                    'reference_code' => $purchase->code,
                    'detail_available' => false,
                ]));
            }
        }

        $returns = PurchaseReturn::query()
            ->where('supplier_id', $customer->id)
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get(['id', 'code', 'total_amount', 'refund_amount', 'status', 'return_date', 'created_at']);

        foreach ($returns as $return) {
            $entries->push($this->entry([
                'id' => 'pret-' . $return->id,
                'code' => $return->code,
                'display_type' => 'Trả hàng nhập',
                'event_kind' => 'purchase_return',
                'domain' => 'supplier',
                'document_amount' => (float) $return->total_amount,
                'amount' => (float) $return->total_amount,
                'display_effect' => (float) $return->total_amount,
                'financial_effect' => (float) $return->total_amount,
                'balance_effect' => (float) $return->total_amount,
                'customer_display_effect' => (float) $return->total_amount,
                'customer_balance_effect' => (float) $return->total_amount,
                'supplier_display_effect' => -(float) $return->total_amount,
                'supplier_balance_effect' => -(float) $return->total_amount,
                'customer_effect' => (float) $return->total_amount,
                'supplier_effect' => -(float) $return->total_amount,
                'affects_debt_balance' => true,
                'source' => 'document',
                'badge_label' => 'Trả hàng nhập',
                'time' => $return->return_date ?? $return->created_at,
                'created_at' => $return->created_at,
                'reference_type' => 'PurchaseReturn',
                'reference_id' => $return->id,
                'reference_code' => $return->code,
                'detail_available' => false,
            ]));
        }

        return $entries->values();
    }

    private function buildSupplierReferenceEntries(Customer $customer): Collection
    {
        $purchasePaidTotal = (float) Purchase::query()
            ->where('supplier_id', $customer->id)
            ->where('status', 'completed')
            ->sum('paid_amount');

        return SupplierDebtTransaction::query()
            ->where('supplier_id', $customer->id)
            ->whereNotIn('type', ['purchase', 'return', 'offset'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($transaction) use ($purchasePaidTotal) {
                [$displayType, $eventKind, $customerEffect, $supplierEffect] = $this->classifySupplierTransaction($transaction);
                $customerDisplayEffect = $customerEffect ?: -1 * (float) ($supplierEffect ?? 0.0);
                $supplierDisplayEffect = $supplierEffect;

                $canAffect = $transaction->type === 'payment'
                    && $this->looksLikeStandaloneSupplierPayment($transaction)
                    && $purchasePaidTotal <= 0;

                if (!$canAffect) {
                    $customerEffect = 0.0;
                    $supplierEffect = null;
                }

                return $this->entry([
                    'id' => 'stx-' . $transaction->id,
                    'code' => $transaction->code,
                    'display_type' => $displayType,
                    'event_kind' => $eventKind,
                    'domain' => $canAffect ? 'supplier' : 'reference',
                    'document_amount' => abs((float) $transaction->amount),
                    'amount' => abs((float) $transaction->amount),
                    'display_effect' => $customerDisplayEffect,
                    'financial_effect' => $customerDisplayEffect,
                    'balance_effect' => $customerEffect,
                    'customer_display_effect' => $customerDisplayEffect,
                    'customer_balance_effect' => $customerEffect,
                    'supplier_display_effect' => $supplierDisplayEffect,
                    'supplier_balance_effect' => $supplierEffect,
                    'customer_effect' => $customerEffect,
                    'supplier_effect' => $supplierEffect,
                    'affects_debt_balance' => $canAffect,
                    'is_reference_only' => !$canAffect,
                    'source' => $canAffect ? 'ledger' : 'reference',
                    'badge_label' => 'Thanh toán NCC',
                    'badge_title' => $canAffect ? null : 'Chứng từ tham chiếu, không cộng lại số dư công nợ.',
                    'balance_note' => $canAffect ? null : 'Đã phản ánh qua phiếu nhập/trả hàng, không cộng lại công nợ.',
                    'time' => $transaction->created_at,
                    'created_at' => $transaction->created_at,
                    'reference_type' => 'SupplierDebtTransaction',
                    'reference_id' => $transaction->id,
                    'reference_code' => $transaction->code,
                    'note' => $transaction->note,
                    'detail_available' => false,
                ]);
            })
            ->values();
    }

    private function computeRunningBalance(Collection $entries): array
    {
        $ledgerRunning = 0.0;
        $displayRunning = 0.0;

        $computed = $entries
            ->sortBy(fn ($entry) => $this->timestamp($entry) . '-' . ($entry['id'] ?? ''))
            ->values()
            ->map(function ($entry) use (&$ledgerRunning, &$displayRunning) {
                $displayBalanceEffect = $this->customerDisplayBalanceEffect($entry);
                $ledgerEffect = $this->customerLedgerEffect($entry);

                if (($entry['affects_debt_balance'] ?? true) === true) {
                    $ledgerRunning += $ledgerEffect;
                }

                $displayRunning += $displayBalanceEffect;

                $entry['ledger_running_balance'] = $ledgerRunning;
                $entry['customer_display_balance_effect'] = $displayBalanceEffect;
                $entry['customer_display_running_balance'] = $displayRunning;
                $entry['customer_running_balance'] = $displayRunning;
                $entry['balance'] = $displayRunning;

                return $entry;
            });

        return [$computed, $ledgerRunning];
    }

    private function mapCustomerDebt(CustomerDebt $debt, array $settlementMetaByDebtId, Collection $discountsByCode): array
    {
        [$displayType, $eventKind] = $this->classifyCustomerDebt($debt);
        $amount = (float) $debt->amount;
        $recordedAt = $debt->recorded_at ?? $debt->created_at;
        $settlementMeta = $settlementMetaByDebtId[$debt->id] ?? null;

        $entry = $this->entry([
            'id' => 'ldg-' . $debt->id,
            'code' => $debt->ref_code,
            'display_type' => $displayType,
            'event_kind' => $eventKind,
            'domain' => 'customer',
            'document_amount' => abs($amount),
            'amount' => $amount,
            'display_effect' => $amount,
            'financial_effect' => $amount,
            'balance_effect' => $amount,
            'customer_display_effect' => $amount,
            'customer_display_balance_effect' => $amount,
            'customer_balance_effect' => $amount,
            'customer_effect' => $amount,
            'affects_debt_balance' => true,
            'source' => 'ledger',
            'badge_label' => 'Ledger',
            'time' => $recordedAt,
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
            'reference_type' => 'CustomerDebt',
            'reference_id' => $debt->id,
            'reference_code' => $debt->ref_code,
            'note' => $debt->note,
            'debt_total' => (float) $debt->debt_total,
            'ledger_debt_total' => (float) $debt->debt_total,
            'type_raw' => $debt->type,
            'detail_available' => true,
        ]);

        if ($settlementMeta) {
            $entry['customer_effect'] = $amount + (float) $settlementMeta['settlement_adjusted_amount'];
            $entry['balance_effect'] = $entry['customer_effect'];
            $entry['customer_display_balance_effect'] = $entry['customer_effect'];
            $entry['customer_balance_effect'] = $entry['customer_effect'];
            $entry['debt_total'] = (float) $settlementMeta['display_balance'];
            $entry['ledger_debt_total'] = (float) $settlementMeta['display_balance'];
            $entry['settlement_adjusted_amount'] = (float) $settlementMeta['settlement_adjusted_amount'];
            $entry['settlement_adjustment_ids'] = $settlementMeta['settlement_adjustment_ids'];
            $entry['display_merged_settlement'] = true;
        }

        $discount = $discountsByCode[$debt->ref_code] ?? null;
        if ($discount) {
            $entry['payment_discount_id'] = $discount->id;
            $entry['payment_discount_status'] = $discount->status;
            $entry['can_cancel'] = $eventKind === 'payment_discount' && $discount->status === 'active';
        }

        return $entry;
    }

    private function classifyCustomerDebt(CustomerDebt $debt): array
    {
        $type = (string) $debt->type;
        $refCode = (string) ($debt->ref_code ?? '');
        $note = mb_strtolower((string) ($debt->note ?? ''));

        if ($type === 'sale') {
            return ['Bán hàng', 'customer_sale'];
        }
        if ($type === 'payment') {
            if (str_starts_with($refCode, 'CKTT')) {
                return [(float) $debt->amount > 0 ? 'Hủy chiết khấu thanh toán' : 'Chiết khấu thanh toán', (float) $debt->amount > 0 ? 'payment_discount_cancel' : 'payment_discount'];
            }
            return ['Khách thanh toán', 'customer_payment'];
        }
        if ($type === 'return') {
            return ['Trả hàng bán', 'sales_return'];
        }
        if ($type === 'sale_reversal' || $this->isInvoiceCancelDebt($debt)) {
            return ['Hủy hóa đơn', 'invoice_cancel'];
        }
        if ($type === 'adjustment') {
            if (str_starts_with($refCode, 'MERGE') || str_contains($note, 'gộp công nợ') || str_contains($note, 'gop cong no')) {
                return ['Số dư đầu kỳ / Gộp công nợ', 'opening_balance'];
            }
            if (str_starts_with($refCode, 'CKTT')) {
                return [(float) $debt->amount > 0 ? 'Hủy chiết khấu thanh toán' : 'Chiết khấu thanh toán', (float) $debt->amount > 0 ? 'payment_discount_cancel' : 'payment_discount'];
            }
            return ['Điều chỉnh công nợ', 'customer_adjustment'];
        }

        return [$type ?: 'Chứng từ tham chiếu', $type ?: 'unknown'];
    }

    private function classifySupplierTransaction(SupplierDebtTransaction $transaction): array
    {
        $amount = (float) $transaction->amount;

        if ($transaction->type === 'payment') {
            return ['Thanh toán NCC', 'supplier_payment', abs($amount), -abs($amount)];
        }
        if ($transaction->type === 'adjustment') {
            return ['Điều chỉnh công nợ NCC', 'supplier_adjustment', -$amount, $amount];
        }
        if ($transaction->type === 'discount') {
            return ['Chiết khấu thanh toán NCC', 'supplier_discount', abs($amount), -abs($amount)];
        }

        return [$transaction->type ?: 'Chứng từ tham chiếu', $transaction->type ?: 'supplier_reference', 0.0, null];
    }

    private function buildReturnSettlementMeta(Collection $debts): array
    {
        $autoSettlements = $debts->filter(fn ($debt) => $this->isAutoReturnSettlement($debt))->values();
        $matchedSettlementIds = [];
        $returnSettlementMeta = [];

        foreach ($debts->where('type', 'return') as $returnDebt) {
            $settlements = $autoSettlements
                ->filter(fn ($settlementDebt) => $this->matchesReturnSettlement($returnDebt, $settlementDebt))
                ->values();

            if ($settlements->isEmpty()) {
                continue;
            }

            $lastSettlement = $settlements
                ->sortBy(fn ($settlementDebt) => $this->timestamp([
                    'time' => $settlementDebt->recorded_at ?? $settlementDebt->created_at,
                    'id' => $settlementDebt->id,
                ]))
                ->last();

            $settlementIds = $settlements->pluck('id')->values()->all();
            $matchedSettlementIds = array_merge($matchedSettlementIds, $settlementIds);

            $returnSettlementMeta[$returnDebt->id] = [
                'display_balance' => (float) $lastSettlement->debt_total,
                'settlement_adjusted_amount' => (float) $settlements->sum('amount'),
                'settlement_adjustment_ids' => $settlementIds,
            ];
        }

        return [array_values(array_unique($matchedSettlementIds)), $returnSettlementMeta];
    }

    private function entry(array $entry): array
    {
        $displayType = $entry['display_type'] ?? $entry['type'] ?? 'Chứng từ tham chiếu';

        return array_merge([
            'id' => null,
            'code' => null,
            'time' => $entry['created_at'] ?? null,
            'created_at' => $entry['time'] ?? null,
            'type' => $displayType,
            'display_type' => $displayType,
            'domain' => 'reference',
            'event_kind' => 'reference',
            'document_amount' => 0.0,
            'amount' => 0.0,
            'display_effect' => null,
            'financial_effect' => null,
            'balance_effect' => null,
            'customer_display_effect' => null,
            'customer_balance_effect' => null,
            'customer_running_balance' => null,
            'customer_display_balance_effect' => null,
            'customer_display_running_balance' => null,
            'supplier_display_effect' => null,
            'supplier_balance_effect' => null,
            'supplier_running_balance' => null,
            'supplier_display_balance_effect' => null,
            'supplier_display_running_balance' => null,
            'customer_effect' => 0.0,
            'supplier_effect' => null,
            'affects_debt_balance' => false,
            'balance' => null,
            'balance_note' => null,
            'source' => 'reference',
            'badge_label' => null,
            'badge_title' => null,
            'detail_available' => false,
            'reference_type' => null,
            'reference_id' => null,
            'reference_code' => null,
            'note' => null,
        ], $entry, [
            'type' => $displayType,
            'display_type' => $displayType,
        ]);
    }

    private function firstNumeric(array $entry, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null && $entry[$key] !== '') {
                return (float) $entry[$key];
            }
        }

        return $default;
    }

    private function customerDisplayEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'customer_display_effect',
            'display_effect',
            'financial_effect',
            'customer_effect',
            'amount',
        ], 0.0);
    }

    private function customerDisplayBalanceEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'customer_display_balance_effect',
            'customer_display_effect',
            'display_effect',
            'financial_effect',
            'customer_effect',
            'amount',
        ], 0.0);
    }

    private function customerLedgerEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'customer_balance_effect',
            'balance_effect',
            'customer_effect',
        ], 0.0);
    }

    private function injectCustomerVirtualOpeningBalance(Collection $entries, Customer $customer, float $targetBalance): Collection
    {
        $displayTotal = (float) $entries->sum(fn ($entry) => $this->customerDisplayEffect(is_array($entry) ? $entry : (array) $entry));
        $openingBalance = $targetBalance - $displayTotal;
        $hasReferenceOnlyFinancialEntry = $entries->contains(function ($entry) {
            $entry = is_array($entry) ? $entry : (array) $entry;

            return (bool) ($entry['is_reference_only'] ?? false)
                && abs($this->customerDisplayEffect($entry)) >= 0.01;
        });

        if ($entries->isNotEmpty() && abs($targetBalance) < 0.01 && !$hasReferenceOnlyFinancialEntry) {
            return $entries->values();
        }

        if (abs($openingBalance) < 0.01) {
            return $entries->values();
        }

        $opening = $this->entry([
            'id' => 'virtual-opening-customer-' . $customer->id,
            'code' => 'OPENING-BALANCE-' . $customer->id,
            'display_type' => 'Số dư đầu kỳ / Điều chỉnh hiển thị',
            'event_kind' => 'virtual_opening_balance',
            'domain' => 'adjustment',
            'document_amount' => abs($openingBalance),
            'amount' => abs($openingBalance),
            'display_effect' => $openingBalance,
            'financial_effect' => $openingBalance,
            'balance_effect' => 0.0,
            'customer_display_effect' => $openingBalance,
            'customer_display_balance_effect' => $openingBalance,
            'customer_balance_effect' => 0.0,
            'customer_effect' => $openingBalance,
            'affects_debt_balance' => false,
            'is_reference_only' => false,
            'is_virtual_opening' => true,
            'source' => 'virtual_display_opening_balance',
            'badge_label' => 'Số dư đầu kỳ',
            'note' => 'Dòng hiển thị để timeline khớp Nợ hiện tại. Không phải chứng từ thật.',
            'balance_note' => 'Dòng hiển thị read-only do thiếu lịch sử chi tiết, không ghi dữ liệu.',
            'time' => $this->virtualOpeningTime($entries),
            'created_at' => $this->virtualOpeningTime($entries),
            'reference_type' => 'VirtualOpeningBalance',
            'reference_id' => $customer->id,
            'reference_code' => 'OPENING-BALANCE-' . $customer->id,
            'detail_available' => false,
        ]);

        return collect([$opening])->concat($entries)->values();
    }

    private function virtualOpeningTime(Collection $entries): Carbon
    {
        $first = $entries
            ->map(fn ($entry) => is_array($entry) ? $entry : (array) $entry)
            ->filter(fn ($entry) => !empty($entry['time'] ?? $entry['created_at'] ?? null))
            ->sortBy(fn ($entry) => $this->timestamp($entry))
            ->first();

        $value = $first['time'] ?? $first['created_at'] ?? null;

        if ($value instanceof Carbon) {
            return $value->copy()->subSecond();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->subSecond();
        }

        if ($value) {
            try {
                return Carbon::parse($value)->subSecond();
            } catch (\Throwable) {
                // Fall through to a stable display timestamp.
            }
        }

        return Carbon::now()->startOfDay();
    }

    private function isAutoReturnSettlement(CustomerDebt $debt): bool
    {
        if ($debt->type !== 'adjustment' || (float) $debt->amount <= 0) {
            return false;
        }

        if (empty($debt->order_return_id) && empty($debt->ref_code)) {
            return false;
        }

        $note = (string) $debt->note;
        return str_contains($note, 'Tat toan tien da tra khach cho phieu tra')
            || str_contains($note, 'Bo sung tat toan tien da tra khach cho phieu tra');
    }

    private function matchesReturnSettlement(CustomerDebt $returnDebt, CustomerDebt $settlementDebt): bool
    {
        if (!empty($returnDebt->order_return_id) && !empty($settlementDebt->order_return_id)
            && (int) $returnDebt->order_return_id === (int) $settlementDebt->order_return_id) {
            return true;
        }

        return !empty($returnDebt->ref_code) && !empty($settlementDebt->ref_code)
            && (string) $returnDebt->ref_code === (string) $settlementDebt->ref_code;
    }

    private function isInvoiceCancelDebt(CustomerDebt $debt): bool
    {
        $note = mb_strtolower((string) ($debt->note ?? ''));

        return (string) $debt->type === 'adjustment'
            && (
                str_contains($note, 'hủy hóa đơn')
                || str_contains($note, 'huy hoa don')
                || str_contains($note, 'đảo công nợ')
                || str_contains($note, 'dao cong no')
            );
    }

    private function looksLikeStandaloneSupplierPayment(SupplierDebtTransaction $transaction): bool
    {
        $code = (string) ($transaction->code ?? '');

        return str_starts_with($code, 'PCPN')
            || str_starts_with($code, 'PC')
            || CashFlow::query()
                ->where('reference_type', 'SupplierPayment')
                ->where('reference_code', $code)
                ->exists();
    }

    private function isCancelledStatus(?string $status): bool
    {
        $normalized = mb_strtolower(trim((string) $status));

        return in_array($normalized, ['đã hủy', 'da huy', 'cancelled', 'canceled', 'void', 'deleted'], true);
    }

    private function timestamp(array $entry): string
    {
        $value = $entry['time'] ?? $entry['recorded_at'] ?? $entry['created_at'] ?? null;

        if ($value instanceof Carbon) {
            return $value->format('YmdHis.u');
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('YmdHis.u');
        }

        $parsed = strtotime((string) $value);
        return $parsed ? date('YmdHis', $parsed) : '00000000000000';
    }
}
