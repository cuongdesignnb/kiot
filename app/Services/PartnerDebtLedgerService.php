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
            $entries->push([
                'id' => 'pur-' . $p->id,
                'code' => $p->code,
                'type' => 'purchase',
                'type_label' => 'Nhập hàng',
                'badge_label' => 'Phiếu nhập',
                'amount' => (float) $p->total_amount,
                'supplier_effect' => (float) $p->total_amount,
                'affects_debt_balance' => true,
                'time' => $p->purchase_date ?? $p->created_at,
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
            $entries->push([
                'id' => 'pret-' . $pr->id,
                'code' => $pr->code,
                'type' => 'return',
                'type_label' => 'Trả hàng nhập',
                'badge_label' => 'Trả hàng nhập',
                'amount' => (float) $pr->total_amount,
                'supplier_effect' => -(float) $pr->total_amount,
                'affects_debt_balance' => true,
                'time' => $pr->return_date ?? $pr->created_at,
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
            $realPayments[(string) $cf->code] = [
                'id' => 'cf-pay-' . $cf->id,
                'code' => $cf->code,
                'type' => 'payment',
                'type_label' => 'Thanh toán',
                'badge_label' => 'Thanh toán',
                'amount' => (float) $cf->amount,
                'supplier_effect' => -(float) $cf->amount,
                'affects_debt_balance' => true,
                'time' => $cf->time ?? $cf->created_at,
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

            $realPayments[$code] = [
                'id' => 'stx-pay-' . $stx->id,
                'code' => $stx->code,
                'type' => 'payment',
                'type_label' => 'Thanh toán',
                'badge_label' => $canAffect ? 'Thanh toán' : 'Đã hạch toán',
                'amount' => abs((float) $stx->amount),
                'supplier_effect' => $canAffect ? (float) $stx->amount : 0.0,
                'affects_debt_balance' => $canAffect,
                'time' => $stx->created_at,
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
                    $entries->push([
                        'id' => 'purpay-' . $p->id,
                        'code' => 'TTNH' . preg_replace('/^PN/', '', (string) $p->code),
                        'type' => 'payment',
                        'type_label' => 'Thanh toán',
                        'badge_label' => 'Thanh toán',
                        'amount' => (float) $p->paid_amount,
                        'supplier_effect' => -(float) $p->paid_amount,
                        'affects_debt_balance' => true,
                        'time' => $p->purchase_date ?? $p->created_at,
                        'created_at' => $p->created_at,
                        'source' => 'legacy_purchase_paid_amount',
                        'reference_type' => 'PurchasePayment',
                        'reference_id' => $p->id,
                        'detail_available' => false,
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
            $entries->push([
                'id' => 'stx-' . $stx->id,
                'code' => $stx->code,
                'type' => $stx->type,
                'type_label' => $typeLabels[$stx->type] ?? $stx->type,
                'badge_label' => $typeLabels[$stx->type] ?? $stx->type,
                'amount' => abs((float) $stx->amount),
                'supplier_effect' => (float) $stx->amount, // positive to increase payable, negative to decrease
                'affects_debt_balance' => true,
                'time' => $stx->created_at,
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

        // 7) Sort by time asc and compute payable running balance
        $sorted = $entries
            ->sortBy(fn ($entry) => $this->timestamp($entry) . '-' . ($entry['id'] ?? ''))
            ->values();

        $balance = 0.0;
        $ledger = $sorted->map(function ($entry) use (&$balance) {
            $balance += (float) ($entry['supplier_effect'] ?? 0.0);
            $entry['supplier_balance'] = $balance;
            $entry['debt_remain'] = $balance;
            $entry['balance'] = $balance;
            return $entry;
        });

        return [
            'entries' => $ledger->values(),
            'closing_balance' => $balance,
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

                $supplierEntries->push($this->entry([
                    'id' => 'sup-mirror-' . ($supEntry['id'] ?? uniqid()),
                    'code' => $supEntry['code'],
                    'display_type' => $supEntry['type_label'] ?? $supEntry['type'],
                    'event_kind' => 'supplier_mirror_' . $supEntry['type'],
                    'domain' => 'supplier',
                    'document_amount' => (float) ($supEntry['amount'] ?? 0.0),
                    'amount' => (float) ($supEntry['amount'] ?? 0.0),
                    'customer_effect' => -1 * (float) ($supEntry['supplier_effect'] ?? 0.0),
                    'supplier_effect' => (float) ($supEntry['supplier_effect'] ?? 0.0),
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

        // 4) Sort by time asc and compute net running balance
        $sorted = $combined
            ->sortBy(fn ($entry) => $this->timestamp($entry) . '-' . ($entry['id'] ?? ''))
            ->values();

        $running = 0.0;
        $computedEntries = $sorted->map(function ($entry) use (&$running) {
            if (($entry['affects_debt_balance'] ?? true) === true) {
                $running += (float) ($entry['customer_effect'] ?? 0.0);
                $entry['balance'] = $running;
            } else {
                $entry['balance'] = null;
            }
            return $entry;
        });

        // 5) Net metrics and reconciliation
        $customerDebt = (float) ($customer->debt_amount ?? 0);
        $supplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $netDebt = $customerDebt - $supplierDebt;
        $computedBalance = (float) ($computedEntries->where('affects_debt_balance', true)->last()['balance'] ?? 0.0);
        $hasMismatch = abs($computedBalance - $netDebt) >= 0.01;

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
            'reconcile' => [
                'current_net_debt' => $netDebt,
                'computed_balance' => $computedBalance,
                'has_mismatch' => $hasMismatch,
                'message' => $hasMismatch
                    ? 'Lịch sử công nợ đang lệch với Nợ hiện tại. Cần đối soát dữ liệu trước khi cập nhật.'
                    : null,
            ],
            'summary' => [
                // Canonical receivable/payable/net keys (HOTFIX FOLLOW-UP)
                'customer_receivable_balance' => $customerDebt,
                'supplier_payable_balance'    => $supplierDebt,
                'partner_net_position'        => $netDebt,
                'has_debt_offset_voucher'     => $hasDebtOffsetVoucher,
                'is_actual_offset'            => false,
                'is_net_view'                 => true,

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
    public function buildSupplierDualRoleDebtTimeline(Customer $partner): array
    {
        $netLedger = $this->buildCustomerNetLedger($partner);
        $summary = $netLedger['summary'] ?? [];

        $entries = collect($netLedger['entries'] ?? [])
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

                $partnerEffect = (float) ($entry['customer_effect'] ?? 0.0);
                $partnerBalance = array_key_exists('balance', $entry) ? $entry['balance'] : null;

                $entry['partner_effect'] = $partnerEffect;
                $entry['partner_running_balance'] = $partnerBalance;
                $entry['affects_partner_net'] = $affects;
                $entry['source_ledger'] = $sourceLedger;
                $entry['is_mirror'] = $source === 'supplier_ledger_mirror';
                $entry['affects_customer_receivable'] = $sourceLedger === 'customer_receivable' && $affects;
                $entry['affects_supplier_payable'] = $sourceLedger === 'supplier_payable' && $affects;
                $entry['debt_remain'] = $partnerBalance;

                if ($sourceLedger === 'debt_offset') {
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
        $summary = array_merge($summary, [
            'display_mode' => 'partner_net_timeline',
            'is_supplier_tab_partner_timeline' => true,
            'is_net_view' => true,
            'net' => $partnerNetPosition,
            'current_debt' => $partnerNetPosition,
            'net_debt_amount' => $partnerNetPosition,
            'source' => 'supplier_partner_financial_timeline',
            'count' => $entries->count(),
        ]);

        return [
            'entries' => $entries,
            'closing_balance' => $partnerNetPosition,
            'summary' => $summary,
            'reconcile' => $netLedger['reconcile'] ?? null,
        ];
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
                'customer_effect' => $hasCustomerLedger ? 0.0 : (float) $invoice->total,
                'affects_debt_balance' => !$hasCustomerLedger,
                'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                'badge_label' => $hasCustomerLedger ? 'Đã hạch toán' : 'Chứng từ cũ',
                'badge_title' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
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
                    'customer_effect' => $hasCustomerLedger ? 0.0 : -(float) $invoice->customer_paid,
                    'affects_debt_balance' => !$hasCustomerLedger,
                    'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                    'badge_label' => $hasCustomerLedger ? 'Đã hạch toán' : 'Thanh toán HĐ',
                    'badge_title' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
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
                    'customer_effect' => 0.0,
                    'affects_debt_balance' => false,
                    'source' => 'reference',
                    'badge_label' => 'Đã hạch toán',
                    'badge_title' => 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.',
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
                'customer_effect' => $affects ? -(float) $return->total : 0.0,
                'affects_debt_balance' => $affects,
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
                    'customer_effect' => $hasCustomerLedger ? 0.0 : -(float) $cashFlow->amount,
                    'affects_debt_balance' => !$hasCustomerLedger,
                    'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                    'badge_label' => $hasCustomerLedger ? 'Đã hạch toán' : 'Chứng từ cũ',
                    'badge_title' => $hasCustomerLedger ? 'Đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger công nợ, không cộng lại công nợ.' : null,
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
