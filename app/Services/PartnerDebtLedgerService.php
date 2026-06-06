<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentDiscount;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Models\CashFlow;
use App\Models\DebtOffset;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PartnerDebtLedgerService
{
    /**
     * Source of truth for the supplier screen tab "Công nợ" (pure supplier-side payable timeline).
     *
     * @param Customer $supplier
     * @return array
     */
    public function buildSupplierPayableLedger(Customer $supplier): array
    {
        $entries = collect();

        // 1) Purchases: "Nhập hàng" -> increases company's payable debt to supplier
        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->where('status', 'completed')
            ->get(['id', 'code', 'total_amount', 'paid_amount', 'purchase_date', 'created_at']);

        foreach ($purchases as $p) {
            $businessTime = $this->normalizeDisplayTime($p->purchase_date, $p->created_at);
            $entries->push([
                'id' => 'pur-' . $p->id,
                'code' => $p->code,
                'type' => 'purchase',
                'type_label' => 'Nhập hàng',
                'badge_label' => 'Phiếu nhập',
                'amount' => (float) $p->total_amount,
                'supplier_effect' => (float) $p->total_amount,
                'display_effect' => (float) $p->total_amount,
                'financial_effect' => (float) $p->total_amount,
                'balance_effect' => (float) $p->total_amount,
                'supplier_display_effect' => (float) $p->total_amount,
                'supplier_balance_effect' => (float) $p->total_amount,
                'affects_debt_balance' => true,
                'display_time' => $businessTime,
                'time' => $businessTime,
                'purchase_date' => $p->purchase_date,
                'created_at' => $p->created_at,
                'source' => 'purchase',
                'reference_type' => 'Purchase',
                'reference_id' => $p->id,
                'detail_available' => true,
            ]);
        }

        // 2) Purchase Returns: "Trả hàng nhập" -> decreases company's payable debt to supplier
        $purchaseReturns = PurchaseReturn::where('supplier_id', $supplier->id)
            ->where('status', 'completed')
            ->get(['id', 'code', 'total_amount', 'return_date', 'created_at']);

        foreach ($purchaseReturns as $pr) {
            $businessTime = $this->normalizeDisplayTime($pr->return_date, $pr->created_at);
            $entries->push([
                'id' => 'pret-' . $pr->id,
                'code' => $pr->code,
                'type' => 'return',
                'type_label' => 'Trả hàng nhập',
                'badge_label' => 'Trả hàng nhập',
                'amount' => (float) $pr->total_amount,
                'supplier_effect' => -(float) $pr->total_amount,
                'display_effect' => -(float) $pr->total_amount,
                'financial_effect' => -(float) $pr->total_amount,
                'balance_effect' => -(float) $pr->total_amount,
                'supplier_display_effect' => -(float) $pr->total_amount,
                'supplier_balance_effect' => -(float) $pr->total_amount,
                'affects_debt_balance' => true,
                'display_time' => $businessTime,
                'time' => $businessTime,
                'return_date' => $pr->return_date,
                'created_at' => $pr->created_at,
                'source' => 'purchase_return',
                'reference_type' => 'PurchaseReturn',
                'reference_id' => $pr->id,
                'detail_available' => true,
            ]);
        }

        // 3) Real Payments: "Thanh toán NCC" -> query CashFlow & SupplierDebtTransaction payments
        // We group real payments by their code to avoid double-counting.
        $realPayments = [];

        // Fetch valid cashflow payments
        $cashflows = CashFlow::query()
            ->where('type', 'payment')
            ->where(function ($q) use ($supplier) {
                $q->where(function ($q2) use ($supplier) {
                    $q2->where('target_id', $supplier->id)
                       ->whereIn('target_type', ['Nha cung cap', 'Nhà cung cấp']);
                })
                ->orWhere(function ($q2) use ($supplier) {
                    $q2->where('reference_type', 'SupplierPayment')
                       ->where('target_id', $supplier->id);
                })
                ->orWhere(function ($q2) use ($supplier) {
                    $q2->where('reference_type', 'Purchase')
                       ->whereIn('reference_code', function ($sub) use ($supplier) {
                           $sub->select('code')->from('purchases')->where('supplier_id', $supplier->id);
                       });
                });
            })
            ->get();

        foreach ($cashflows as $cf) {
            if ($this->isCancelledStatus($cf->status ?? '')) {
                continue;
            }
            $businessTime = $this->normalizeDisplayTime($cf->time, $cf->created_at);
            $realPayments[(string) $cf->code] = [
                'id' => 'cf-pay-' . $cf->id,
                'code' => $cf->code,
                'type' => 'payment',
                'type_label' => 'Thanh toán',
                'badge_label' => 'Thanh toán',
                'amount' => (float) $cf->amount,
                'supplier_effect' => -(float) $cf->amount,
                'display_effect' => -(float) $cf->amount,
                'financial_effect' => -(float) $cf->amount,
                'balance_effect' => -(float) $cf->amount,
                'supplier_display_effect' => -(float) $cf->amount,
                'supplier_balance_effect' => -(float) $cf->amount,
                'affects_debt_balance' => true,
                'display_time' => $businessTime,
                'time' => $businessTime,
                'created_at' => $cf->created_at,
                'source' => 'cashflow',
                'reference_type' => 'CashFlow',
                'reference_id' => $cf->id,
                'note' => $cf->description ?? $cf->note,
                'detail_available' => true,
            ];
        }

        // Fetch SupplierDebtTransaction payments (standalone or manual payments)
        $supplierTxsPayments = SupplierDebtTransaction::where('supplier_id', $supplier->id)
            ->where('type', 'payment')
            ->get();

        // HOTFIX FOLLOW-UP — per-transaction guard. The old gate
        // `$canAffect = $isStandalone && $purchasePaidTotal <= 0` would
        // silently mark every standalone supplier payment as "Đã hạch
        // toán" the moment ANY purchase on this supplier had a non-zero
        // paid_amount — even when that paid_amount belongs to a totally
        // unrelated purchase. The fix is per-transaction: a standalone
        // payment counts unless its own code is already represented in
        // realPayments (the CashFlow loop above) or there is a directly
        // linked Purchase/CashFlow row carrying the same payment.
        foreach ($supplierTxsPayments as $stx) {
            $code = (string) $stx->code;
            if (isset($realPayments[$code])) {
                continue;
            }

            $isStandalone = $this->looksLikeStandaloneSupplierPayment($stx);
            $alreadyAccounted = $this->supplierTransactionAlreadyAccountedFor($stx, $realPayments, $purchases);
            $canAffect = $isStandalone && !$alreadyAccounted;
            $businessTime = $this->supplierDebtTransactionBusinessTime($stx);

            $realPayments[$code] = [
                'id' => 'stx-pay-' . $stx->id,
                'code' => $stx->code,
                'type' => 'payment',
                'type_label' => 'Thanh toán',
                'badge_label' => 'Thanh toán',
                'amount' => abs((float) $stx->amount),
                'supplier_effect' => $canAffect ? (float) $stx->amount : 0.0,
                'display_effect' => (float) $stx->amount,
                'financial_effect' => (float) $stx->amount,
                'balance_effect' => $canAffect ? (float) $stx->amount : 0.0,
                'supplier_display_effect' => (float) $stx->amount,
                'supplier_balance_effect' => $canAffect ? (float) $stx->amount : 0.0,
                'affects_debt_balance' => $canAffect,
                'display_time' => $businessTime,
                'time' => $businessTime,
                'recorded_at' => Schema::hasColumn('supplier_debt_transactions', 'recorded_at') ? ($stx->recorded_at ?? null) : null,
                'created_at' => $stx->created_at,
                'source' => $canAffect ? 'supplier_debt_transaction' : 'reference',
                'reference_type' => 'SupplierDebtTransaction',
                'reference_id' => $stx->id,
                'note' => $stx->note,
                'detail_available' => false,
            ];
        }

        // Push all real payments
        foreach ($realPayments as $paymentEntry) {
            $entries->push($paymentEntry);
        }

        // 4) Legacy/Virtual Payments: Generate virtual payments from Purchase.paid_amount
        // ONLY when no corresponding CashFlow or SupplierDebtTransaction exists.
        foreach ($purchases as $p) {
            if ((float) $p->paid_amount > 0) {
                // Check if this purchase's payment is already represented in real payments
                $hasRealPayment = false;
                foreach ($realPayments as $pay) {
                    if ($pay['reference_type'] === 'CashFlow' && $pay['note'] && str_contains($pay['note'], $p->code)) {
                        $hasRealPayment = true;
                        break;
                    }
                    if ($pay['code'] === 'PCPN' . preg_replace('/^PN/', '', $p->code) || $pay['code'] === 'TTNH' . preg_replace('/^PN/', '', $p->code)) {
                        $hasRealPayment = true;
                        break;
                    }
                }
                
                // HOTFIX FOLLOW-UP — NULL-safe cancelled guard.
                // `status != 'cancelled'` drops NULL rows; scopeNotCancelledCashFlow
                // keeps them in scope so a real payment is correctly detected
                // and no virtual TTNH duplicate gets synthesised.
                $hasLinkedCashFlow = $this->scopeNotCancelledCashFlow(
                    CashFlow::where('reference_type', 'Purchase')
                        ->where('reference_code', $p->code)
                        ->where('type', 'payment')
                )->exists();

                if (!$hasRealPayment && !$hasLinkedCashFlow) {
                    $businessTime = $this->normalizeDisplayTime($p->purchase_date, $p->created_at);
                    $entries->push([
                        'id' => 'purpay-' . $p->id,
                        'code' => 'TTNH' . preg_replace('/^PN/', '', (string) $p->code),
                        'type' => 'payment',
                        'type_label' => 'Thanh toán',
                        'badge_label' => 'Thanh toán',
                        'amount' => (float) $p->paid_amount,
                        'supplier_effect' => -(float) $p->paid_amount,
                        'display_effect' => -(float) $p->paid_amount,
                        'financial_effect' => -(float) $p->paid_amount,
                        'balance_effect' => -(float) $p->paid_amount,
                        'supplier_display_effect' => -(float) $p->paid_amount,
                        'supplier_balance_effect' => -(float) $p->paid_amount,
                        'affects_debt_balance' => true,
                        'display_time' => $businessTime,
                        'time' => $businessTime,
                        'purchase_date' => $p->purchase_date,
                        'created_at' => $p->created_at,
                        'source' => 'legacy_purchase_paid_amount',
                        'reference_type' => 'PurchasePayment',
                        'reference_id' => $p->id,
                        'detail_available' => false,
                        // STEP 10 — synthesised from Purchase.paid_amount; no real phiếu chi.
                        'is_virtual_fallback' => true,
                        'is_real_voucher' => false,
                        'badge_title' => 'Tạm tính từ phiếu nhập — chưa có phiếu chi thật.',
                    ]);
                }
            }
        }

        // 5) Standalone adjustments, discounts, and offsets from SupplierDebtTransaction
        $otherTxs = SupplierDebtTransaction::where('supplier_id', $supplier->id)
            ->whereNotIn('type', ['purchase', 'return', 'payment'])
            ->get();

        $typeLabels = [
            'adjustment' => 'Điều chỉnh',
            'discount' => 'Chiết khấu TT',
            'offset' => 'Điều chỉnh', // CB/HCB cấn bằng công nợ kiêm NCC hiển thị là Điều chỉnh giống Kiot
        ];

        foreach ($otherTxs as $stx) {
            $businessTime = $this->supplierDebtTransactionBusinessTime($stx);
            $entries->push([
                'id' => 'stx-' . $stx->id,
                'code' => $stx->code,
                'type' => $stx->type,
                'type_label' => $typeLabels[$stx->type] ?? $stx->type,
                'badge_label' => $typeLabels[$stx->type] ?? $stx->type,
                'amount' => abs((float) $stx->amount),
                'supplier_effect' => (float) $stx->amount, // positive to increase payable, negative to decrease
                'display_effect' => (float) $stx->amount,
                'financial_effect' => (float) $stx->amount,
                'balance_effect' => (float) $stx->amount,
                'supplier_display_effect' => (float) $stx->amount,
                'supplier_balance_effect' => (float) $stx->amount,
                'affects_debt_balance' => true,
                'display_time' => $businessTime,
                'time' => $businessTime,
                'recorded_at' => Schema::hasColumn('supplier_debt_transactions', 'recorded_at') ? ($stx->recorded_at ?? null) : null,
                'created_at' => $stx->created_at,
                'source' => 'supplier_debt_transaction',
                'reference_type' => 'SupplierDebtTransaction',
                'reference_id' => $stx->id,
                'note' => $stx->note,
                'detail_available' => false,
            ]);
        }

        // 6) DebtOffset (CB/HCB) — HOTFIX FOLLOW-UP. KiotViet treats CB as
        //    a one-sided payable reduction on the supplier screen; the
        //    customer-net view picks it up via the mirror in
        //    buildCustomerNetLedger() so both screens show the same
        //    voucher with opposite signs. Active CB reduces payable
        //    (supplier_effect = -amount); cancelled CB (HCB) restores it.
        // CB always emitted as -amount at created_at (reduces payable as
        // it actually happened). If cancelled, an additional HCB row at
        // cancelled_at adds +amount back so the post-cancellation balance
        // returns to the pre-CB state.
        //
        // Dedup: legacy data sometimes carries the same CB as a
        // SupplierDebtTransaction type='offset' row AND as a DebtOffset
        // row with the same code. We already emitted the SupplierDebt-
        // Transaction in section 5; skip the DebtOffset row if a
        // SupplierDebtTransaction with the same code already supplied it.
        $existingCodes = $entries->pluck('code')->filter()->map(fn ($c) => (string) $c)->all();
        $offsets = DebtOffset::where('customer_id', $supplier->id)
            ->whereNotIn('code', $existingCodes)
            ->get();
        foreach ($offsets as $offset) {
            $entries->push([
                'id' => 'offset-' . $offset->id,
                'code' => $offset->code,
                'type' => 'offset',
                'type_label' => 'Điều chỉnh',
                'badge_label' => 'Điều chỉnh',
                'amount' => (float) $offset->amount,
                'supplier_effect' => -(float) $offset->amount,
                'display_effect' => -(float) $offset->amount,
                'financial_effect' => -(float) $offset->amount,
                'balance_effect' => -(float) $offset->amount,
                'supplier_display_effect' => -(float) $offset->amount,
                'supplier_balance_effect' => -(float) $offset->amount,
                'affects_debt_balance' => true,
                'time' => $offset->created_at,
                'created_at' => $offset->created_at,
                'source' => 'debt_offset',
                'reference_type' => 'DebtOffset',
                'reference_id' => $offset->id,
                'note' => $offset->note,
                'detail_available' => false,
            ]);

            if ($offset->status === 'cancelled') {
                $cancelCode = 'HCB' . str_pad((string) $offset->id, 6, '0', STR_PAD_LEFT);
                $entries->push([
                    'id' => 'offset-cancel-' . $offset->id,
                    'code' => $cancelCode,
                    'type' => 'offset_cancel',
                    'type_label' => 'Hủy điều chỉnh',
                    'badge_label' => 'Hủy điều chỉnh',
                    'amount' => (float) $offset->amount,
                    'supplier_effect' => +(float) $offset->amount,
                    'display_effect' => (float) $offset->amount,
                    'financial_effect' => (float) $offset->amount,
                    'balance_effect' => (float) $offset->amount,
                    'supplier_display_effect' => (float) $offset->amount,
                    'supplier_balance_effect' => (float) $offset->amount,
                    'affects_debt_balance' => true,
                    'time' => $offset->cancelled_at ?? $offset->updated_at,
                    'created_at' => $offset->cancelled_at ?? $offset->updated_at,
                    'source' => 'debt_offset_cancel',
                    'reference_type' => 'DebtOffsetCancel',
                    'reference_id' => $offset->id,
                    'note' => $offset->cancel_reason ?? 'Hủy điều chỉnh',
                    'detail_available' => false,
                ]);
            }
        }

        $targetBalance = (float) ($supplier->supplier_debt_amount ?? 0.0);
        $entries = $this->injectSupplierVirtualOpeningBalance($entries, $supplier, $targetBalance);

        // 7) Sort by time asc and compute payable running balance
        $sorted = $entries
            ->sortBy(fn ($entry) => $this->ledgerSortKey($entry))
            ->values();

        $ledger = $this->computeSupplierDisplayRunningBalance($sorted);
        $balance = (float) ($ledger->last()['supplier_display_running_balance'] ?? 0.0);
        $virtualOpening = $ledger->firstWhere('is_virtual_opening', true);
        $hasVirtualOpening = (bool) $virtualOpening;
        $ledgerBalance = (float) ($ledger->last()['supplier_ledger_running_balance'] ?? 0.0);
        $reconcile = $this->buildDisplayReconcilePayload(
            $targetBalance,
            $ledgerBalance,
            $targetBalance,
            $balance,
            $hasVirtualOpening,
            'Nợ cần trả nhà cung cấp'
        );

        return [
            'entries' => $ledger->values(),
            'closing_balance' => $balance,
            'reconcile' => $reconcile,
            'summary' => [
                'display_timeline_mode' => true,
                'has_virtual_opening_balance' => $hasVirtualOpening,
                'virtual_opening_balance' => (float) ($virtualOpening['supplier_display_effect'] ?? 0.0),
                'display_balance_target' => $targetBalance,
                'display_balance_final' => $balance,
            ],
        ];
    }

    /**
     * Source of truth for pure customer-side receivable timeline.
     *
     * @param Customer $customer
     * @return array
     */
    public function buildCustomerReceivableLedger(Customer $customer): array
    {
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

        $ledgerCodes = $ledgerEntries->pluck('code')->filter()->map(fn ($code) => (string) $code)->all();
        $legacyFiltered = $legacyEntries
            ->filter(fn ($entry) => !in_array((string) ($entry['code'] ?? ''), $ledgerCodes, true))
            ->values();

        $combined = $ledgerEntries->concat($legacyFiltered);

        // HOTFIX FOLLOW-UP — Append customer-side DebtOffset entries ONLY
        // for pure-customer (non-dual-role) partners. For dual-role, CB is
        // emitted on the supplier ledger and mirrored into the customer-net
        // view (see buildSupplierPayableLedger section 6 + buildCustomerNetLedger
        // mirror at line 389); emitting it here too would double-count.
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) ($customer->is_customer && ($hasSupplierColumn ? $customer->is_supplier : false));

        $offsets = $isDualRole ? collect() : DebtOffset::where('customer_id', $customer->id)->get();
        foreach ($offsets as $offset) {
            $combined->push($this->entry([
                'id' => 'offset-' . $offset->id,
                'code' => $offset->code,
                'display_type' => 'Điều chỉnh',
                'event_kind' => 'debt_offset',
                'domain' => 'customer',
                'document_amount' => (float) $offset->amount,
                'amount' => (float) $offset->amount,
                'customer_effect' => -(float) $offset->amount,
                'affects_debt_balance' => true,
                'source' => 'debt_offset',
                'badge_label' => 'Điều chỉnh',
                'time' => $offset->created_at,
                'created_at' => $offset->created_at,
                'reference_type' => 'DebtOffset',
                'reference_id' => $offset->id,
                'reference_code' => $offset->code,
                'note' => $offset->note,
                'detail_available' => false,
            ]));

            if ($offset->status === 'cancelled') {
                $cancelCode = 'HCB' . str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                $combined->push($this->entry([
                    'id' => 'offset-cancel-' . $offset->id,
                    'code' => $cancelCode,
                    'display_type' => 'Hủy điều chỉnh',
                    'event_kind' => 'debt_offset_cancel',
                    'domain' => 'customer',
                    'document_amount' => (float) $offset->amount,
                    'amount' => (float) $offset->amount,
                    'customer_effect' => (float) $offset->amount,
                    'affects_debt_balance' => true,
                    'source' => 'debt_offset_cancel',
                    'badge_label' => 'Điều chỉnh',
                    'time' => $offset->cancelled_at ?? $offset->updated_at,
                    'created_at' => $offset->cancelled_at ?? $offset->updated_at,
                    'reference_type' => 'DebtOffsetCancel',
                    'reference_id' => $offset->id,
                    'reference_code' => $offset->code,
                    'note' => $offset->cancel_reason ?? 'Hủy cấn bằng',
                    'detail_available' => false,
                ]));
            }
        }

        return [
            'entries' => $combined->values(),
            'has_customer_ledger' => $hasCustomerLedger,
        ];
    }

    /**
     * Source of truth for customer net timeline (receivables - payables combined).
     *
     * @param Customer $customer
     * @return array
     */
    public function buildCustomerNetLedger(Customer $customer): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) ($customer->is_customer && ($hasSupplierColumn ? $customer->is_supplier : false));

        // 1) Fetch customer receivable ledger
        $customerLedger = $this->buildCustomerReceivableLedger($customer);
        $customerEntries = collect($customerLedger['entries']);

        // 2) Fetch supplier payable ledger mirror if dual role
        $supplierEntries = collect();
        if ($isDualRole) {
            $supplierLedger = $this->buildSupplierPayableLedger($customer);
            foreach ($supplierLedger['entries'] as $supEntry) {
                // Mirror sign: customer_effect = -1 * supplier_effect
                $affects = (bool) ($supEntry['affects_debt_balance'] ?? true);
                $supplierDisplayEffect = $this->firstNumeric($supEntry, [
                    'supplier_display_effect',
                    'display_effect',
                    'financial_effect',
                    'supplier_effect',
                ], 0.0);
                $supplierBalanceEffect = $this->firstNumeric($supEntry, [
                    'supplier_balance_effect',
                    'balance_effect',
                    'supplier_effect',
                ], 0.0);

                $supplierEntries->push($this->entry([
                    'id' => 'sup-mirror-' . ($supEntry['id'] ?? uniqid()),
                    'code' => $supEntry['code'],
                    'display_type' => $supEntry['type_label'] ?? $supEntry['type'],
                    'event_kind' => 'supplier_mirror_' . $supEntry['type'],
                    'domain' => 'supplier',
                    'document_amount' => (float) ($supEntry['amount'] ?? 0.0),
                    'amount' => (float) ($supEntry['amount'] ?? 0.0),
                    'display_effect' => -1 * $supplierDisplayEffect,
                    'financial_effect' => -1 * $supplierDisplayEffect,
                    'balance_effect' => -1 * $supplierBalanceEffect,
                    'customer_display_effect' => -1 * $supplierDisplayEffect,
                    'customer_balance_effect' => -1 * $supplierBalanceEffect,
                    'customer_effect' => -1 * $supplierBalanceEffect,
                    'supplier_display_effect' => $supplierDisplayEffect,
                    'supplier_balance_effect' => $supplierBalanceEffect,
                    'supplier_effect' => $supplierBalanceEffect,
                    'affects_debt_balance' => $affects,
                    'source' => 'supplier_ledger_mirror',
                    'time' => $supEntry['time'],
                    'created_at' => $supEntry['created_at'],
                    'reference_type' => $supEntry['reference_type'] ?? null,
                    'reference_id' => $supEntry['reference_id'] ?? null,
                    'reference_code' => $supEntry['reference_code'] ?? null,
                    'detail_available' => $supEntry['detail_available'] ?? false,
                    'badge_label' => $supEntry['badge_label'] ?? null,
                    'note' => $supEntry['note'] ?? null,
                ]));
            }
        }

        // 3) Combine them
        $combined = $customerEntries->concat($supplierEntries);

        $customerDebt = (float) ($customer->debt_amount ?? 0);
        $supplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $netDebt = $customerDebt - $supplierDebt;
        $combined = $this->injectCustomerVirtualOpeningBalance($combined, $customer, $netDebt);

        // 4) Sort by time asc and compute net running balance
        $sorted = $combined
            ->sortBy(fn ($entry) => $this->ledgerSortKey($entry))
            ->values();

        [$computedEntries, $ledgerClosingBalance] = $this->computeCustomerDisplayRunningBalance($sorted);

        // 5) Net metrics and reconciliation
        $ledgerBalance = $ledgerClosingBalance;
        $displayFinalBalance = (float) ($computedEntries->last()['customer_display_running_balance'] ?? 0.0);
        $virtualOpening = $computedEntries->firstWhere('is_virtual_opening', true);
        $hasVirtualOpening = (bool) $virtualOpening;
        $reconcile = $this->buildDisplayReconcilePayload(
            $netDebt,
            $ledgerBalance,
            $netDebt,
            $displayFinalBalance,
            $hasVirtualOpening,
            'Nợ hiện tại'
        );

        // HOTFIX FOLLOW-UP — `partner_net_position` is the canonical
        // semantic key. `net_debt_amount` is kept for backward
        // compatibility but UI/reports should migrate to the new key,
        // which makes it clear this is a display delta, not a recorded
        // debt offset (no CB voucher implied).
        // DebtOffset only stores customer_id (dual-role partners use the
        // same customer record for both roles), so a single where suffices.
        $hasDebtOffsetVoucher = DebtOffset::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        return [
            'entries' => $computedEntries->sortByDesc(fn ($entry) => $this->timestamp($entry))->values(),
            'reconcile' => $reconcile,
            'summary' => [
                // Canonical receivable/payable/net keys (HOTFIX FOLLOW-UP)
                'customer_receivable_balance' => $customerDebt,
                'supplier_payable_balance'    => $supplierDebt,
                'partner_net_position'        => $netDebt,
                'has_debt_offset_voucher'     => $hasDebtOffsetVoucher,
                'is_actual_offset'            => false,
                'is_net_view'                 => true,
                'display_timeline_mode'        => true,
                'has_virtual_opening_balance'  => $hasVirtualOpening,
                'virtual_opening_balance'      => (float) ($virtualOpening['customer_display_effect'] ?? 0.0),
                'display_balance_target'       => $netDebt,
                'display_balance_final'        => $displayFinalBalance,

                // Backward-compatible keys (do NOT remove — FE/tests reference them)
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
            ],
        ];
    }

    /**
     * Supplier-screen opt-in partner timeline for dual-role partners.
     *
     * The default supplier tab/export must stay a pure payable ledger.
     * This view reuses the customer net timeline so dual-role supplier tabs
     * can show the partner financial story without changing payable totals.
     */
    public function buildSupplierDualRolePartnerTimeline(Customer $partner): array
    {
        $netLedger = $this->buildCustomerNetLedger($partner);
        $summary = $netLedger['summary'] ?? [];

        $entries = collect($netLedger['entries'] ?? [])
            ->sortBy(fn ($entry) => $this->ledgerSortKey($entry))
            ->map(function ($entry) {
                $entry = is_array($entry) ? $entry : (array) $entry;
                $source = (string) ($entry['source'] ?? '');
                $referenceType = (string) ($entry['reference_type'] ?? '');
                $domain = (string) ($entry['domain'] ?? 'reference');
                $affects = (bool) ($entry['affects_debt_balance'] ?? true);

                $sourceLedger = 'customer_receivable';
                if ($source === 'supplier_ledger_mirror' || $domain === 'supplier') {
                    $sourceLedger = 'supplier_payable';
                }
                if (str_contains($source, 'debt_offset') || str_contains($referenceType, 'DebtOffset')) {
                    $sourceLedger = 'debt_offset';
                }
                if ((bool) ($entry['is_virtual_opening'] ?? false)) {
                    $sourceLedger = 'virtual_opening_balance';
                }

                $customerDisplayEffect = $this->firstNumeric($entry, [
                    'customer_display_effect',
                    'display_effect',
                    'financial_effect',
                    'customer_effect',
                ], 0.0);
                [$partnerDisplayEffect, $partnerBalanceEffect] = $this->resolveSupplierPartnerEffects($entry, $sourceLedger);

                $entry['orientation'] = 'supplier';
                $entry['display_effect'] = $partnerDisplayEffect;
                $entry['financial_effect'] = $partnerDisplayEffect;
                $entry['balance_effect'] = $partnerBalanceEffect;
                $entry['supplier_display_effect'] = $partnerDisplayEffect;
                $entry['supplier_balance_effect'] = $partnerBalanceEffect;
                $entry['supplier_partner_effect'] = $partnerDisplayEffect;
                $entry['partner_effect'] = $partnerDisplayEffect;
                $entry['affects_partner_net'] = $affects;
                $entry['source_ledger'] = $sourceLedger;
                $entry['is_mirror'] = $source === 'supplier_ledger_mirror';
                $entry['affects_customer_receivable'] = $sourceLedger === 'customer_receivable' && $affects;
                $entry['affects_supplier_payable'] = $sourceLedger === 'supplier_payable' && $affects;
                $entry['display_balance_label'] = 'Nợ cần trả nhà cung cấp';
                $entry['view_effects'] = [
                    'customer' => $customerDisplayEffect,
                    'supplier' => $partnerDisplayEffect,
                ];

                if ($sourceLedger === 'virtual_opening_balance') {
                    $entry['domain'] = 'adjustment';
                    $entry['badge_label'] = 'Số dư đầu kỳ';
                } elseif ($sourceLedger === 'debt_offset') {
                    $entry['domain'] = 'offset';
                    $entry['badge_label'] = 'Cấn trừ';
                } elseif ($sourceLedger === 'supplier_payable') {
                    $entry['domain'] = 'supplier';
                    $entry['badge_label'] = 'Phải trả NCC';
                } elseif ($sourceLedger === 'customer_receivable') {
                    $entry['domain'] = 'customer';
                    $entry['badge_label'] = 'Phải thu KH';
                }

                return $entry;
            })
            ->values();

        $partnerNetPosition = (float) ($summary['partner_net_position'] ?? $summary['net'] ?? 0.0);
        $supplierOrientedBalance = -1 * $partnerNetPosition;
        $entries = $this->injectSupplierVirtualOpeningBalance($entries, $partner, $supplierOrientedBalance, true);
        $entries = $this->computeSupplierDisplayRunningBalance($entries, true)
            ->sortByDesc(fn ($entry) => $this->timestamp($entry))
            ->values();

        $chronologicalEntries = $entries->sortBy(fn ($entry) => $this->timestamp($entry))->values();
        $virtualOpening = $chronologicalEntries->firstWhere('is_virtual_opening', true);
        $hasVirtualOpening = (bool) $virtualOpening;
        $displayFinalBalance = (float) ($chronologicalEntries->last()['supplier_display_running_balance'] ?? 0.0);
        $ledgerBalance = (float) ($chronologicalEntries->last()['supplier_ledger_running_balance'] ?? 0.0);
        $reconcile = $this->buildDisplayReconcilePayload(
            $supplierOrientedBalance,
            $ledgerBalance,
            $supplierOrientedBalance,
            $displayFinalBalance,
            $hasVirtualOpening,
            'Nợ cần trả nhà cung cấp'
        );
        $summary = array_merge($summary, [
            'display_mode' => 'supplier_partner_timeline',
            'legacy_display_mode' => 'partner_net_timeline',
            'orientation' => 'supplier',
            'is_supplier_tab_partner_timeline' => true,
            'is_net_view' => true,
            'supplier_oriented_balance' => $supplierOrientedBalance,
            'supplier_partner_balance' => $supplierOrientedBalance,
            'supplier_screen_balance' => $supplierOrientedBalance,
            'net' => $supplierOrientedBalance,
            'current_debt' => $supplierOrientedBalance,
            'net_debt_amount' => $partnerNetPosition,
            'balance_label' => 'Nợ cần trả nhà cung cấp',
            'source' => 'supplier_partner_financial_timeline',
            'display_timeline_mode' => true,
            'has_virtual_opening_balance' => $hasVirtualOpening,
            'virtual_opening_balance' => (float) ($virtualOpening['supplier_display_effect'] ?? 0.0),
            'display_balance_target' => $supplierOrientedBalance,
            'display_balance_final' => $displayFinalBalance,
            'count' => $entries->count(),
        ]);

        return [
            'entries' => $entries,
            'closing_balance' => $supplierOrientedBalance,
            'summary' => $summary,
            'reconcile' => $reconcile,
        ];
    }

    private function buildDisplayReconcilePayload(
        float $storedBalance,
        float $ledgerBalance,
        float $displayBalanceTarget,
        float $displayBalanceFinal,
        bool $hasVirtualOpeningBalance,
        string $balanceLabel = 'Nợ hiện tại'
    ): array {
        $ledgerMismatch = abs($ledgerBalance - $storedBalance) >= 0.01;
        $displayMismatch = abs($displayBalanceFinal - $displayBalanceTarget) >= 0.01;
        $displayResolved = !$displayMismatch;

        $severity = 'ok';
        $message = null;
        $userWarning = false;

        if ($displayMismatch) {
            $severity = 'warning';
            $message = 'Lịch sử công nợ đang lệch với ' . $balanceLabel . '. Cần đối soát dữ liệu trước khi cập nhật.';
            $userWarning = true;
        } elseif ($ledgerMismatch && $hasVirtualOpeningBalance) {
            $severity = 'info';
            $message = 'Timeline dùng số dư đầu kỳ hiển thị do thiếu lịch sử chi tiết.';
        }

        return [
            'has_mismatch' => $displayMismatch,
            'ledger_mismatch' => $ledgerMismatch,
            'display_mismatch' => $displayMismatch,
            'display_resolved' => $displayResolved,
            'stored_balance' => $storedBalance,
            'current_net_debt' => $storedBalance,
            'ledger_balance' => $ledgerBalance,
            'computed_balance' => $ledgerBalance,
            'display_balance_target' => $displayBalanceTarget,
            'display_balance_final' => $displayBalanceFinal,
            'has_virtual_opening_balance' => $hasVirtualOpeningBalance,
            'resolved_by_virtual_opening' => $ledgerMismatch && $displayResolved && $hasVirtualOpeningBalance,
            'severity' => $severity,
            'user_warning' => $userWarning,
            'message' => $message,
        ];
    }

    private function resolveSupplierPartnerEffects(array $entry, string $sourceLedger): array
    {
        if ($sourceLedger === 'supplier_payable') {
            $display = $this->firstNumeric($entry, [
                'supplier_display_effect',
                'display_effect',
                'financial_effect',
                'supplier_effect',
            ], 0.0);
            $balance = $this->firstNumeric($entry, [
                'supplier_balance_effect',
                'balance_effect',
                'supplier_effect',
            ], 0.0);

            return [$display, $balance];
        }

        $customerDisplay = $this->firstNumeric($entry, [
            'customer_display_effect',
            'display_effect',
            'financial_effect',
            'customer_effect',
            'amount',
        ], 0.0);
        $customerBalance = $this->firstNumeric($entry, [
            'customer_balance_effect',
            'balance_effect',
            'customer_effect',
        ], 0.0);

        return [-1 * $customerDisplay, -1 * $customerBalance];
    }

    // ==========================================
    // Customer timeline helper methods (copied from PartnerFinancialTimelineService)
    // ==========================================

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

    /**
     * STEP 10 — Real (non-cancelled) receipt cashflows that reference an
     * invoice, keyed by the invoice code. Used so the invoice-payment line
     * can show the genuine phiếu thu voucher (code + click target) when one
     * exists. If two receipts share an invoice code, keep the first. This is
     * a read-only lookup; it does not change any balance.
     *
     * @return Collection<string,\App\Models\CashFlow>
     */
    private function realInvoiceReceiptsByCode(Customer $customer): Collection
    {
        return CashFlow::active()
            ->where('target_type', 'Khách hàng')
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->where('reference_type', 'Invoice')
            ->whereNotNull('reference_code')
            ->orderBy('created_at')
            ->get()
            ->reject(fn ($cf) => $this->isDebtAdjustmentCashFlow($cf))
            ->keyBy('reference_code');
    }

    private function buildCustomerLegacyEntries(Customer $customer, bool $hasCustomerLedger, Collection $customerDebts): Collection
    {
        $entries = collect();

        // STEP 10 — preload real invoice receipts so the payment line can
        // show the REAL phiếu thu code/voucher when one exists, instead of
        // always synthesising a virtual TTHD line. Keyed by invoice code.
        $receiptsByInvoiceCode = $this->realInvoiceReceiptsByCode($customer);

        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get(['id', 'code', 'total', 'customer_paid', 'status', 'created_at', 'transaction_date']);

        foreach ($invoices as $invoice) {
            if ($this->isCancelledStatus($invoice->status)) {
                continue;
            }
            $businessTime = $this->normalizeDisplayTime($invoice->transaction_date, $invoice->created_at);

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
                'display_time' => $businessTime,
                'time' => $businessTime,
                'transaction_date' => $invoice->transaction_date,
                'created_at' => $invoice->created_at,
                'reference_type' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_code' => $invoice->code,
                'detail_available' => true,
                // STEP 10 — explicit click metadata + ordering sequence.
                'detail_modal_type' => 'invoice',
                'detail_reference_type' => 'Invoice',
                'detail_reference_id' => $invoice->id,
                'detail_reference_code' => $invoice->code,
                'is_real_voucher' => true,
                'ledger_sequence' => 10,
                'display_sequence' => 10,
            ]));

            if ((float) $invoice->customer_paid > 0) {
                // STEP 10 — prefer the REAL phiếu thu when it exists.
                // Balance semantics (the `$hasCustomerLedger ? 0.0 : ...`
                // ternaries) stay IDENTICAL — only the displayed identity
                // (code, badge, click target, time) changes.
                $realReceipt = $receiptsByInvoiceCode[$invoice->code] ?? null;

                if ($realReceipt) {
                    $paymentTime = $this->normalizeDisplayTime($realReceipt->time, $realReceipt->created_at);
                    $identity = [
                        'id' => 'invpay-' . $invoice->id,
                        'code' => $realReceipt->code,                 // mã PT thật
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'is_virtual_payment' => false,
                        'badge_label' => 'Thanh toán',
                        'badge_title' => $hasCustomerLedger ? 'Chứng từ tham chiếu, không cộng lại số dư công nợ.' : 'Phiếu thu thật của hóa đơn.',
                        'display_time' => $paymentTime,
                        'time' => $paymentTime,
                        'detail_modal_type' => 'cash_flow',
                        'detail_reference_type' => 'CashFlow',
                        'detail_reference_id' => $realReceipt->id,
                        'detail_reference_code' => $realReceipt->code,
                    ];
                } else {
                    // badge_label stays 'Thanh toán' (no churn on existing
                    // assertions). The fallback nature is carried by
                    // is_virtual_fallback + badge_title, which the frontend
                    // renders as a small "tạm tính" indicator.
                    $identity = [
                        'id' => 'invpay-' . $invoice->id,
                        'code' => 'TTHD' . preg_replace('/^HD/', '', (string) $invoice->code),
                        'is_real_voucher' => false,
                        'is_virtual_fallback' => true,
                        'is_virtual_payment' => true,
                        'badge_label' => 'Thanh toán',
                        'badge_title' => $hasCustomerLedger
                            ? 'Chứng từ tham chiếu — chưa có phiếu thu thật, không cộng lại công nợ.'
                            : 'Tạm tính từ hóa đơn — chưa có phiếu thu thật.',
                        'display_time' => $businessTime,
                        'time' => $businessTime,
                        'detail_modal_type' => 'cash_flow',
                        'detail_reference_type' => 'Invoice',
                        'detail_reference_id' => $invoice->id,
                        'detail_reference_code' => $invoice->code,
                    ];
                }

                $entries->push($this->entry(array_merge([
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
                    'balance_note' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
                    'transaction_date' => $invoice->transaction_date,
                    'created_at' => $invoice->created_at,
                    'reference_type' => 'InvoicePayment',
                    'reference_id' => $invoice->id,
                    'reference_code' => $invoice->code,
                    'detail_available' => true,
                    'ledger_sequence' => 20,
                    'display_sequence' => 20,
                ], $identity)));
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

        $returnColumns = ['id', 'code', 'total', 'paid_to_customer', 'status', 'created_at'];
        if (Schema::hasColumn('returns', 'return_date')) {
            $returnColumns[] = 'return_date';
        }

        $returns = OrderReturn::query()
            ->where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get($returnColumns);

        foreach ($returns as $return) {
            if ($this->isCancelledStatus($return->status)) {
                continue;
            }
            $businessTime = $this->normalizeDisplayTime($return->return_date ?? null, $return->created_at);

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
                    'display_time' => $businessTime,
                    'time' => $businessTime,
                    'return_date' => $return->return_date ?? null,
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
                'display_time' => $businessTime,
                'time' => $businessTime,
                'return_date' => $return->return_date ?? null,
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

        $regularEntries = CashFlow::query()
            ->where('target_type', 'Khách hàng')
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->whereNotIn('reference_type', ['DebtOffset', 'DebtOffsetCancel'])
            ->orderBy('created_at')
            ->get()
            ->filter(function ($cashFlow) use ($invoiceCodes) {
                if ($this->isDebtAdjustmentCashFlow($cashFlow)) {
                    return false;
                }

                return !($cashFlow->reference_type === 'Invoice' && in_array($cashFlow->reference_code, $invoiceCodes, true));
            })
            ->map(function ($cashFlow) use ($hasCustomerLedger) {
                $businessTime = $this->normalizeDisplayTime($cashFlow->time, $cashFlow->created_at);
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
                    'display_time' => $businessTime,
                    'time' => $businessTime,
                    'created_at' => $cashFlow->created_at,
                    'reference_type' => $cashFlow->reference_type,
                    'reference_id' => $cashFlow->id,
                    'reference_code' => $cashFlow->reference_code,
                    'detail_available' => true,
                ]);
            })
            ->values();

        return $regularEntries
            ->concat($this->buildDebtAdjustmentCashFlowDisplayEntries($customer))
            ->values();
    }

    private function buildDebtAdjustmentCashFlowDisplayEntries(Customer $customer): Collection
    {
        return CashFlow::query()
            ->where('type', 'receipt')
            ->where(function ($query) use ($customer) {
                $query->where('target_id', $customer->id);

                if (Schema::hasColumn('cash_flows', 'customer_id')) {
                    $query->orWhere('customer_id', $customer->id);
                }
            })
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($cashFlow) => $this->isDebtAdjustmentCashFlow($cashFlow))
            ->filter(fn ($cashFlow) => $this->isSameCustomerCashFlow($customer, $cashFlow))
            ->reject(fn ($cashFlow) => $this->hasCustomerDebtRepresentingCashFlow((int) $customer->id, $cashFlow))
            ->map(fn ($cashFlow) => $this->mapDebtAdjustmentCashFlowEntry($cashFlow))
            ->values();
    }

    private function mapDebtAdjustmentCashFlowEntry($cashFlow): array
    {
        $amount = abs((float) $cashFlow->amount);
        $businessTime = $this->normalizeDisplayTime($cashFlow->time, $cashFlow->created_at);
        $entry = $this->entry([
            'id' => 'cf-debt-adjustment-' . $cashFlow->id,
            'code' => $cashFlow->code,
            'display_type' => 'Dieu chinh cong no',
            'event_kind' => 'debt_adjustment',
            'domain' => 'customer',
            'document_amount' => $amount,
            'amount' => -$amount,
            'display_effect' => -$amount,
            'financial_effect' => -$amount,
            'balance_effect' => 0.0,
            'customer_display_effect' => -$amount,
            'customer_display_balance_effect' => -$amount,
            'customer_balance_effect' => 0.0,
            'customer_effect' => 0.0,
            'affects_debt_balance' => false,
            'is_reference_only' => true,
            'is_virtual_display_adjustment' => true,
            'is_debt_adjustment_cashflow' => true,
            'source' => 'cash_flow',
            'badge_label' => 'Dieu chinh',
            'badge_title' => 'Hien thi tu phieu dieu chinh cong no, khong ghi ledger moi.',
            'balance_note' => 'Display-only DebtAdjustment cashflow; khong ghi customer_debts va khong cap nhat so du.',
            'note' => $cashFlow->description ?? $cashFlow->note,
            'description' => $cashFlow->description ?? $cashFlow->note,
            'display_time' => $businessTime,
            'time' => $businessTime,
            'created_at' => $cashFlow->created_at,
            'reference_type' => $cashFlow->reference_type,
            'reference_id' => $cashFlow->id,
            'reference_code' => $cashFlow->reference_code,
            'detail_available' => true,
        ]);
        $entry['type'] = 'debt_adjustment';

        return $entry;
    }

    private function isDebtAdjustmentCashFlow($cashFlow): bool
    {
        if ($this->isCancelledStatus((string) ($cashFlow->status ?? ''))) {
            return false;
        }

        if ((float) ($cashFlow->amount ?? 0) <= 0) {
            return false;
        }

        $referenceType = strtoupper((string) ($cashFlow->reference_type ?? ''));
        $text = $this->normalizeDebtAdjustmentSignalText((string) (($cashFlow->description ?? '') . ' ' . ($cashFlow->note ?? '')));

        return $referenceType === 'DEBTADJUSTMENT'
            || str_contains($text, 'DIEUCHINHCONGNO')
            || str_contains($text, 'CONGNO');
    }

    private function hasCustomerDebtRepresentingCashFlow(int $customerId, $cashFlow): bool
    {
        $code = (string) ($cashFlow->code ?? '');
        if ($code === '' && empty($cashFlow->id)) {
            return false;
        }

        return CustomerDebt::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($cashFlow, $code) {
                if (Schema::hasColumn('customer_debts', 'cash_flow_id') && !empty($cashFlow->id)) {
                    $query->orWhere('cash_flow_id', $cashFlow->id);
                }
                if ($code !== '' && Schema::hasColumn('customer_debts', 'ref_code')) {
                    $query->orWhere('ref_code', $code);
                }
                if ($code !== '' && Schema::hasColumn('customer_debts', 'reference_code')) {
                    $query->orWhere('reference_code', $code);
                }
                if ($code !== '' && Schema::hasColumn('customer_debts', 'note')) {
                    $query->orWhere('note', 'like', '%' . $code . '%');
                }
            })
            ->exists();
    }

    private function isSameCustomerCashFlow(Customer $customer, $cashFlow): bool
    {
        if ((string) ($cashFlow->target_id ?? '') === (string) $customer->id) {
            return true;
        }

        return Schema::hasColumn('cash_flows', 'customer_id')
            && (string) ($cashFlow->customer_id ?? '') === (string) $customer->id;
    }

    private function normalizeDebtAdjustmentSignalText(string $text): string
    {
        $text = strtoupper($text);
        $text = str_replace(['->', ',', '.', ' ', '|', '-', '_'], '', $text);

        return preg_replace('/[^A-Z0-9]/', '', $text) ?: '';
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
            'display_time' => $recordedAt,
            'time' => $recordedAt,
            'recorded_at' => $recordedAt,
            'created_at' => $debt->created_at,
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

    /**
     * Single source of truth for "what counts as cancelled" across
     * isCancelledStatus() (PHP-side) and scopeNotCancelledCashFlow()
     * (DB-side). Adding a new variant here keeps both paths aligned.
     *
     * Values are lower-cased + trimmed; callers must normalise input
     * the same way before comparison.
     */
    private function cancelledStatuses(): array
    {
        return ['đã hủy', 'da huy', 'cancelled', 'canceled', 'void', 'deleted'];
    }

    private function isCancelledStatus(?string $status): bool
    {
        $normalized = mb_strtolower(trim((string) $status));
        return in_array($normalized, $this->cancelledStatuses(), true);
    }

    /**
     * NULL-safe + accent-aware "not cancelled" scope for CashFlow queries.
     *
     * `where('status', '!=', 'cancelled')` would silently drop rows
     * whose status is NULL (legacy data), making the service think no
     * real cashflow exists for a purchase and then synthesise a virtual
     * TTNH payment from Purchase.paid_amount — double-counting the
     * payment. NULL rows must stay in scope (treated as valid legacy
     * payments) and the explicit cancelled variants — including the
     * Vietnamese "Đã hủy" / "da huy" forms — must be excluded.
     *
     * We normalise the column with LOWER+TRIM to defeat case/whitespace
     * drift in legacy data (eg "DA HUY", " Đã Hủy ").
     */
    private function scopeNotCancelledCashFlow($query)
    {
        $cancelled = $this->cancelledStatuses();

        return $query->where(function ($q) use ($cancelled) {
            $q->whereNull('status')
              ->orWhere(function ($q2) use ($cancelled) {
                  $q2->whereNotNull('status')
                     ->whereNotIn(\DB::raw('LOWER(TRIM(status))'), $cancelled);
              });
        });
    }

    private function timestamp(array $entry): string
    {
        $value = $this->entryDisplayTime($entry);

        if ($value instanceof Carbon) {
            return $value->format('YmdHis.u');
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('YmdHis.u');
        }

        $parsed = strtotime((string) $value);
        return $parsed ? date('YmdHis', $parsed) : '00000000000000';
    }

    /**
     * STEP 10 — ASC sort key for running-balance computation. Kept
     * BYTE-EQUIVALENT to the previous `timestamp . '-' . id` form so the
     * balance machinery (virtual opening bridge, dual running balances)
     * is unchanged. Sequence is intentionally NOT used here — reordering
     * the balance pass would shift per-entry running balances. Display
     * ordering uses displaySortKey() instead, which never affects balance.
     */
    private function ledgerSortKey($entry): string
    {
        $e = is_array($entry) ? $entry : (array) $entry;
        return $this->timestamp($e) . '-' . ($e['id'] ?? '');
    }

    private function normalizeDisplayTime($businessTime, $fallback = null)
    {
        return $businessTime ?: $fallback;
    }

    private function entryDisplayTime(array $entry)
    {
        return $entry['display_time']
            ?? $entry['time']
            ?? $entry['recorded_at']
            ?? $entry['transaction_date']
            ?? $entry['purchase_date']
            ?? $entry['return_date']
            ?? $entry['created_at']
            ?? null;
    }

    private function supplierDebtTransactionBusinessTime(SupplierDebtTransaction $transaction)
    {
        if (Schema::hasColumn('supplier_debt_transactions', 'recorded_at')) {
            return $this->normalizeDisplayTime($transaction->recorded_at ?? null, $transaction->created_at);
        }

        return $transaction->created_at;
    }

    private function entry(array $entry): array
    {
        $displayType = $entry['display_type'] ?? $entry['type'] ?? 'Chứng từ tham chiếu';

        $displayTime = $this->entryDisplayTime($entry);
        $createdAt = $entry['created_at'] ?? $displayTime;

        return array_merge([
            'id' => null,
            'code' => null,
            'display_time' => $displayTime,
            'time' => $displayTime,
            'created_at' => $createdAt,
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
            // STEP 10 — KiotViet-style voucher metadata. These are
            // display-only; they NEVER affect balance computation.
            'is_real_voucher' => false,
            'is_virtual_fallback' => false,
            'detail_modal_type' => null,        // 'invoice'|'cash_flow'|'purchase'|'purchase_return'|'offset'|'none'
            'detail_reference_type' => null,
            'detail_reference_id' => null,
            'detail_reference_code' => null,
            'ledger_sequence' => 50,            // balance ASC tiebreaker (same timestamp)
            'display_sequence' => 50,           // display DESC tiebreaker (same timestamp)
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

    private function supplierDisplayEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'supplier_display_effect',
            'supplier_partner_effect',
            'display_effect',
            'financial_effect',
            'partner_effect',
            'supplier_effect',
            'amount',
        ], 0.0);
    }

    private function supplierDisplayBalanceEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'supplier_display_balance_effect',
            'supplier_display_effect',
            'supplier_partner_effect',
            'display_effect',
            'financial_effect',
            'partner_effect',
            'supplier_effect',
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

    private function supplierLedgerEffect(array $entry): float
    {
        return $this->firstNumeric($entry, [
            'supplier_balance_effect',
            'balance_effect',
            'supplier_effect',
            'partner_effect',
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

        $businessTime = $this->virtualOpeningTime($entries);

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
            'display_time' => $businessTime,
            'time' => $businessTime,
            'created_at' => $businessTime,
            'reference_type' => 'VirtualOpeningBalance',
            'reference_id' => $customer->id,
            'reference_code' => 'OPENING-BALANCE-' . $customer->id,
            'detail_available' => false,
        ]);

        return collect([$opening])->concat($entries)->values();
    }

    private function injectSupplierVirtualOpeningBalance(
        Collection $entries,
        Customer $partner,
        float $targetBalance,
        bool $isPartnerTimeline = false
    ): Collection {
        $displayTotal = (float) $entries->sum(fn ($entry) => $this->supplierDisplayEffect(is_array($entry) ? $entry : (array) $entry));
        $openingBalance = $targetBalance - $displayTotal;

        if (!$isPartnerTimeline && $entries->isNotEmpty() && abs($targetBalance) < 0.01) {
            return $entries->values();
        }

        if (abs($openingBalance) < 0.01) {
            return $entries->values();
        }

        $code = ($isPartnerTimeline ? 'OPENING-BALANCE-SUPPLIER-' : 'OPENING-BALANCE-SUPPLIER-') . $partner->id;
        $businessTime = $this->virtualOpeningTime($entries);
        $opening = $this->entry([
            'id' => 'virtual-opening-supplier-' . $partner->id,
            'code' => $code,
            'display_type' => 'Số dư đầu kỳ / Điều chỉnh hiển thị',
            'type_label' => 'Số dư đầu kỳ / Điều chỉnh hiển thị',
            'event_kind' => 'virtual_opening_balance',
            'domain' => 'adjustment',
            'document_amount' => abs($openingBalance),
            'amount' => abs($openingBalance),
            'display_effect' => $openingBalance,
            'financial_effect' => $openingBalance,
            'balance_effect' => 0.0,
            'supplier_display_effect' => $openingBalance,
            'supplier_display_balance_effect' => $openingBalance,
            'supplier_balance_effect' => 0.0,
            'supplier_effect' => 0.0,
            'supplier_partner_effect' => $openingBalance,
            'partner_effect' => $openingBalance,
            'affects_debt_balance' => false,
            'affects_partner_net' => false,
            'is_reference_only' => false,
            'is_virtual_opening' => true,
            'source' => 'virtual_display_opening_balance',
            'source_ledger' => 'virtual_opening_balance',
            'badge_label' => 'Số dư đầu kỳ',
            'note' => 'Dòng hiển thị để timeline khớp Nợ cần trả hiện tại. Không phải chứng từ thật.',
            'balance_note' => 'Dòng hiển thị read-only do thiếu lịch sử chi tiết, không ghi dữ liệu.',
            'display_time' => $businessTime,
            'time' => $businessTime,
            'created_at' => $businessTime,
            'reference_type' => 'VirtualOpeningBalance',
            'reference_id' => $partner->id,
            'reference_code' => $code,
            'detail_available' => false,
        ]);

        return collect([$opening])->concat($entries)->values();
    }

    private function computeCustomerDisplayRunningBalance(Collection $entries): array
    {
        $ledgerRunning = 0.0;
        $displayRunning = 0.0;

        $computed = $entries
            ->sortBy(fn ($entry) => $this->ledgerSortKey($entry))
            ->values()
            ->map(function ($entry) use (&$ledgerRunning, &$displayRunning) {
                $entry = is_array($entry) ? $entry : (array) $entry;
                $displayEffect = $this->customerDisplayEffect($entry);
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

    private function computeSupplierDisplayRunningBalance(Collection $entries, bool $isPartnerTimeline = false): Collection
    {
        $ledgerRunning = 0.0;
        $displayRunning = 0.0;

        return $entries
            ->sortBy(fn ($entry) => $this->ledgerSortKey($entry))
            ->values()
            ->map(function ($entry) use (&$ledgerRunning, &$displayRunning, $isPartnerTimeline) {
                $entry = is_array($entry) ? $entry : (array) $entry;
                $displayEffect = $this->supplierDisplayEffect($entry);
                $displayBalanceEffect = $this->supplierDisplayBalanceEffect($entry);
                $ledgerEffect = $this->supplierLedgerEffect($entry);
                $affects = $isPartnerTimeline
                    ? (bool) ($entry['affects_partner_net'] ?? $entry['affects_debt_balance'] ?? true)
                    : (bool) ($entry['affects_debt_balance'] ?? true);

                if ($affects) {
                    $ledgerRunning += $ledgerEffect;
                }

                $displayRunning += $displayBalanceEffect;

                $entry['supplier_ledger_running_balance'] = $ledgerRunning;
                $entry['supplier_display_balance_effect'] = $displayBalanceEffect;
                $entry['supplier_display_running_balance'] = $displayRunning;
                $entry['supplier_running_balance'] = $displayRunning;
                $entry['supplier_partner_running_balance'] = $displayRunning;
                $entry['partner_running_balance'] = $displayRunning;
                $entry['debt_remain'] = $displayRunning;
                $entry['balance'] = $displayRunning;

                return $entry;
            });
    }

    private function virtualOpeningTime(Collection $entries): Carbon
    {
        $first = $entries
            ->map(fn ($entry) => is_array($entry) ? $entry : (array) $entry)
            ->filter(fn ($entry) => !empty($this->entryDisplayTime($entry)))
            ->sortBy(fn ($entry) => $this->timestamp($entry))
            ->first();

        $value = $first ? $this->entryDisplayTime($first) : null;

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

    /**
     * HOTFIX FOLLOW-UP — Decide whether a SupplierDebtTransaction payment
     * is already represented elsewhere (real cashflow or purchase legacy
     * paid_amount), and therefore should NOT have its supplier_effect
     * double-applied to the ledger.
     *
     * Per-transaction (not per-supplier): replaces the old
     * `$purchasePaidTotal <= 0` gate which falsely marked every
     * standalone payment as "Đã hạch toán" the moment any unrelated
     * purchase had paid_amount > 0.
     */
    private function supplierTransactionAlreadyAccountedFor(
        SupplierDebtTransaction $transaction,
        array $realPayments,
        Collection $purchases
    ): bool {
        $code = (string) ($transaction->code ?? '');

        // 1. Same code already collected from CashFlow loop.
        if ($code !== '' && isset($realPayments[$code])) {
            return true;
        }

        // 2. Transaction note explicitly references a Purchase code that
        // already has paid_amount > 0 — treat as legacy accounting note.
        $note = mb_strtolower((string) ($transaction->note ?? ''));
        foreach ($purchases as $purchase) {
            $purchaseCode = mb_strtolower((string) $purchase->code);
            if ($purchaseCode === '' || (float) $purchase->paid_amount <= 0) {
                continue;
            }
            if ($note !== '' && str_contains($note, $purchaseCode)) {
                return true;
            }
        }

        return false;
    }
}
