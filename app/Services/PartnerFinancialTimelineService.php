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

        $computedEntries = $this->computeRunningBalance($combined);

        $customerDebt = (float) ($customer->debt_amount ?? 0);
        $supplierDebt = $hasSupplierColumn ? (float) ($customer->supplier_debt_amount ?? 0) : 0.0;
        $netDebt = $customerDebt - $supplierDebt;
        $computedBalance = (float) ($computedEntries->where('affects_debt_balance', true)->last()['balance'] ?? 0.0);
        $hasMismatch = abs($computedBalance - $netDebt) >= 0.01;

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
            'summary' => [
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
            ],
        ];
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
                'customer_effect' => $hasCustomerLedger ? 0.0 : (float) $invoice->total,
                'affects_debt_balance' => !$hasCustomerLedger,
                'source' => $hasCustomerLedger ? 'reference' : 'legacy',
                'badge_label' => $hasCustomerLedger ? 'Tham khảo' : 'Chứng từ cũ',
                'badge_title' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ' : null,
                'balance_note' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ. Không tính lại công nợ.' : null,
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
                    'badge_label' => $hasCustomerLedger ? 'Tham khảo' : 'Thanh toán HĐ',
                    'badge_title' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ' : null,
                    'balance_note' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ. Không tính lại công nợ.' : null,
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
                    'badge_label' => 'Tham khảo',
                    'badge_title' => 'Đã hạch toán qua ledger công nợ',
                    'balance_note' => 'Đã hạch toán qua ledger công nợ. Không tính lại công nợ.',
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
                    'badge_label' => $hasCustomerLedger ? 'Tham khảo' : 'Chứng từ cũ',
                    'badge_title' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ' : null,
                    'balance_note' => $hasCustomerLedger ? 'Đã hạch toán qua ledger công nợ. Không tính lại công nợ.' : null,
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
                    'customer_effect' => $customerEffect,
                    'supplier_effect' => $supplierEffect,
                    'affects_debt_balance' => $canAffect,
                    'source' => $canAffect ? 'ledger' : 'reference',
                    'badge_label' => $canAffect ? 'Thanh toán NCC' : 'Tham khảo',
                    'badge_title' => $canAffect ? null : 'Không tính lại vì timeline đang dùng thông tin từ phiếu nhập/trả hàng.',
                    'balance_note' => $canAffect ? null : 'Không tính vào công nợ NCC vì đã sử dụng thông tin từ phiếu nhập/trả hàng.',
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

    private function computeRunningBalance(Collection $entries): Collection
    {
        $running = 0.0;

        return $entries
            ->sortBy(fn ($entry) => $this->timestamp($entry) . '-' . ($entry['id'] ?? ''))
            ->values()
            ->map(function ($entry) use (&$running) {
                if (($entry['affects_debt_balance'] ?? true) === true) {
                    $running += (float) ($entry['customer_effect'] ?? 0);
                    $entry['balance'] = $running;
                } else {
                    $entry['balance'] = null;
                }

                return $entry;
            });
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
