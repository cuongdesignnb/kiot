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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierDebtDomainEventSource
{
    /**
     * Return supplier-domain evidence only. The public supplier timeline is
     * now an orientation adapter over the combined canonical event stream.
     */
    public function events(Customer $supplier, array $options = []): Collection
    {
        $payload = $this->build($supplier, array_merge($options, [
            'canonical' => true,
        ]));

        return collect($payload['entries'] ?? [])->values();
    }

    public function build(Customer $supplier, array $options = []): array
    {
        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = $hasSupplierColumn && PartnerDebtDisplayBalance::isDualRole($supplier);
        $usePartnerTimeline = $isDualRole && (string) ($options['view'] ?? '') === 'partner';

        $entries = collect();
        $purchases = collect();
        $invoices = collect();
        $excludedLedgerEntries = [];
        $includeTechnical = (bool) ($options['include_technical'] ?? $options['audit'] ?? false);

        // 1. Purchases
        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->get()
            ->values();

        $purchaseCodes = $purchases->pluck('code')->filter()->toArray();

        foreach ($purchases as $p) {
            $businessTime = $p->purchase_date ?: $p->created_at;
            $entries->push($this->createEntry([
                'id' => 'purchase-'.$p->id,
                'code' => $p->code,
                'display_type' => 'Nhập hàng',
                'event_kind' => 'purchase',
                'domain' => 'supplier',
                'source_status' => $p->status,
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

            // A cancelled document remains auditable. Its economic effect is
            // neutralised by a persisted-document reversal instead of hiding
            // the original row from the timeline.
            if (BusinessStatus::isCancelled($p->status)) {
                $cancelledAt = $p->cancelled_at ?: $p->updated_at ?: $businessTime;
                $entries->push($this->createEntry([
                    'id' => 'purchase-cancel-'.$p->id,
                    'code' => 'HUY-'.$p->code,
                    'display_type' => 'Hủy phiếu nhập',
                    'event_kind' => 'purchase_cancel_reversal',
                    'domain' => 'supplier',
                    'document_amount' => (float) $p->total_amount,
                    'amount' => -(float) $p->total_amount,
                    'display_effect' => -(float) $p->total_amount,
                    'supplier_display_effect' => -(float) $p->total_amount,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'reference_type' => 'Purchase',
                    'reference_id' => $p->id,
                    'reference_code' => $p->code,
                    'reversal_of' => 'supplier|purchases|'.$p->id.'|purchase|payable',
                    'source_table' => 'purchases',
                    'source_id' => $p->id,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                    'document_group_parent_code' => $p->code,
                    'sort_group_time' => $businessTime,
                    'sort_group_sequence' => 90,
                ]));
            }
        }

        // 2. Payment CashFlows targeting Nhà cung cấp (both linked and standalone)
        $supplierPayments = CashFlow::active()
            ->where('type', 'payment')
            ->where(function ($q) use ($supplier, $purchaseCodes) {
                $q->where(function ($q2) use ($supplier) {
                    $q2->where('target_id', $supplier->id)
                        ->whereIn('target_type', PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES);
                })
                    ->orWhere(function ($q2) use ($supplier) {
                        $q2->where('reference_type', 'SupplierPayment')
                            ->where('target_id', $supplier->id);
                    })
                    ->orWhere(function ($q2) use ($purchaseCodes) {
                        $q2->where('reference_type', 'Purchase')
                            ->whereIn('target_type', PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES)
                            ->whereIn('reference_code', $purchaseCodes);
                    });
            })
            ->get();
        $persistedAllocations = Schema::hasTable('supplier_payment_allocations')
            ? DB::table('supplier_payment_allocations')
                ->whereIn('payment_id', $supplierPayments->pluck('id'))
                ->orderBy('payment_id')
                ->orderBy('purchase_id')
                ->get()
            : collect();
        $allocationsByPayment = $persistedAllocations->groupBy('payment_id');
        $purchasesById = $purchases->keyBy('id');

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
        $directPaymentAmountById = [];
        foreach ($paymentsByPurchase as $refCode => $cfs) {
            $purchase = $purchases->firstWhere('code', $refCode);
            $remainingObligation = $purchase ? $this->purchasePaymentObligation($purchase) : 0.0;

            foreach (collect($cfs)->sort(function (CashFlow $left, CashFlow $right): int {
                $timeComparison = strcmp(
                    $this->normalizeSortableTime($left->time ?: $left->created_at),
                    $this->normalizeSortableTime($right->time ?: $right->created_at),
                );

                return $timeComparison !== 0
                    ? $timeComparison
                    : ((int) $left->id <=> (int) $right->id);
            }) as $cashFlow) {
                $canonicalAmount = min(max(0.0, (float) $cashFlow->amount), $remainingObligation);
                $directPaymentAmountById[(int) $cashFlow->id] = $canonicalAmount;
                $remainingObligation = max(0.0, $remainingObligation - $canonicalAmount);
            }

            $realPaymentCoverageByPurchase[$refCode] = (float) collect($cfs)
                ->sum(fn (CashFlow $cashFlow): float => (float) ($directPaymentAmountById[(int) $cashFlow->id] ?? 0.0));
        }
        $directPaymentIds = collect($paymentsByPurchase)
            ->flatMap(fn (array $cashFlows): array => $cashFlows)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $persistedAllocationAmountByKey = [];
        $remainingPersistedAllocationByPayment = $supplierPayments
            ->mapWithKeys(fn (CashFlow $payment): array => [
                (int) $payment->id => max(0.0, (float) $payment->amount),
            ])
            ->all();
        foreach ($persistedAllocations as $allocation) {
            if ($directPaymentIds->has((int) $allocation->payment_id)) {
                continue;
            }
            $purchaseCode = (string) ($purchasesById->get($allocation->purchase_id)?->code ?? '');
            if ($purchaseCode !== '') {
                $purchase = $purchasesById->get($allocation->purchase_id);
                $remainingObligation = max(
                    0.0,
                    $this->purchasePaymentObligation($purchase)
                        - (float) ($realPaymentCoverageByPurchase[$purchaseCode] ?? 0.0)
                );
                $remainingPayment = max(
                    0.0,
                    (float) ($remainingPersistedAllocationByPayment[(int) $allocation->payment_id] ?? 0.0),
                );
                $canonicalAmount = min(
                    max(0.0, (float) $allocation->amount),
                    $remainingObligation,
                    $remainingPayment,
                );
                $persistedAllocationAmountByKey[
                    (int) $allocation->payment_id.':'.(int) $allocation->purchase_id
                ] = $canonicalAmount;
                $remainingPersistedAllocationByPayment[(int) $allocation->payment_id]
                    = max(0.0, $remainingPayment - $canonicalAmount);
                $realPaymentCoverageByPurchase[$purchaseCode]
                    = (float) ($realPaymentCoverageByPurchase[$purchaseCode] ?? 0)
                    + $canonicalAmount;
            }
        }

        $genericPaymentInference = $this->inferGenericSupplierPaymentCoverage(
            $purchases,
            collect($standalonePayments)
                ->reject(fn (CashFlow $payment): bool => $allocationsByPayment->has($payment->id))
                ->values(),
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
            $purchasePaid = $purchase ? $this->purchasePaymentObligation($purchase) : 0.0;
            $paymentTotal = (float) collect($cfs)
                ->sum(fn (CashFlow $cashFlow): float => (float) ($directPaymentAmountById[(int) $cashFlow->id] ?? 0.0));
            $mismatch = abs($paymentTotal - $purchasePaid) > 0.01;

            foreach ($cfs as $index => $cf) {
                $canonicalAmount = (float) ($directPaymentAmountById[(int) $cf->id] ?? 0.0);
                if ($canonicalAmount <= 0.01) {
                    continue;
                }

                $businessTime = $cf->time ?: $cf->created_at;
                $purchaseTime = $purchase ? ($purchase->purchase_date ?: $purchase->created_at) : ($cf->time ?: $cf->created_at);
                $entries->push($this->createEntry([
                    'id' => 'cash_flow-'.$cf->id,
                    'code' => $cf->code,
                    'display_type' => 'Thanh toán NCC',
                    'event_kind' => 'supplier_payment',
                    'domain' => 'supplier',
                    'document_amount' => $canonicalAmount,
                    'amount' => $canonicalAmount,
                    'display_effect' => -$canonicalAmount,
                    'supplier_display_effect' => -$canonicalAmount,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $cf->created_at,
                    'reference_type' => 'Purchase',
                    'reference_id' => $purchase ? $purchase->id : null,
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
                    'badge_title' => $mismatch ? 'Tổng phiếu chi thật không khớp số đã thanh toán trên hóa đơn nhập.' : null,
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'payment_allocation_mismatch' => $mismatch,
                    'needs_manual_review' => $mismatch,
                    'original_cash_flow_amount' => (float) $cf->amount,
                    'non_debt_cash_amount' => max(0.0, (float) $cf->amount - $canonicalAmount),
                    'payment_obligation_evidence' => 'purchases.total_amount_minus_debt_amount',
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

        // 3. Fallback payment from the persisted purchase obligation. The
        // cash voucher may also contain acquisition costs, which are not
        // supplier-debt mutations and must not reduce payable a second time.
        foreach ($purchases as $p) {
            $paidAmount = $this->purchasePaymentObligation($p);
            if ($paidAmount > 0) {
                $coveredAmount = max(0.0, (float) ($realPaymentCoverageByPurchase[$p->code] ?? 0.0));
                $genericInferredCoveredAmount = max(0.0, (float) ($genericPaymentCoverageByPurchase[$p->code] ?? 0.0));
                $uncoveredPaidAmount = max(0.0, $paidAmount - $coveredAmount);

                if ($uncoveredPaidAmount > 0.01) {
                    $businessTime = $p->purchase_date ?: $p->created_at;
                    $entries->push($this->createEntry([
                        'id' => 'purpay-fallback-'.$p->id,
                        'code' => 'TTNH'.preg_replace('/^PN/', '', $p->code),
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
                        'linked_document_label' => 'Thanh toán cho '.$p->code,
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
            $actualAllocations = collect($allocationsByPayment->get($cf->id, []));
            $actualAllocatedAmount = (float) $actualAllocations->sum('amount');
            $hasActualAllocations = $actualAllocations->isNotEmpty();
            $allocationMismatch = $hasActualAllocations
                && abs($actualAllocatedAmount - (float) $cf->amount) > 0.01;
            if ($hasActualAllocations) {
                foreach ($actualAllocations as $allocation) {
                    $purchase = $purchasesById->get($allocation->purchase_id);
                    $originalAllocatedAmount = (float) $allocation->amount;
                    $allocatedAmount = (float) ($persistedAllocationAmountByKey[
                        (int) $allocation->payment_id.':'.(int) $allocation->purchase_id
                    ] ?? 0.0);
                    if ($allocatedAmount <= 0.01) {
                        continue;
                    }

                    $entries->push($this->createEntry([
                        'id' => 'cash-flow-allocation-'.$cf->id.'-purchase-'.$allocation->purchase_id,
                        'code' => $cf->code,
                        'display_type' => 'Thanh toán NCC',
                        'event_kind' => 'supplier_payment',
                        'domain' => 'supplier',
                        'document_amount' => $allocatedAmount,
                        'amount' => $allocatedAmount,
                        'display_effect' => -$allocatedAmount,
                        'supplier_display_effect' => -$allocatedAmount,
                        'time' => $businessTime,
                        'display_time' => $businessTime,
                        'created_at' => $cf->created_at,
                        'reference_type' => 'Purchase',
                        'reference_id' => $allocation->purchase_id,
                        'reference_code' => $purchase?->code,
                        'parent_document_code' => $purchase?->code,
                        'detail_available' => true,
                        'detail_modal_type' => 'cash_flow',
                        'detail_reference_id' => $cf->id,
                        'detail_reference_code' => $cf->code,
                        'source_table' => 'cash_flows',
                        'source_id' => $cf->id.':purchase:'.$allocation->purchase_id,
                        'badge_label' => 'Thanh toán',
                        'badge_title' => $cf->description ?: $cf->note,
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'payment_allocation_confidence' => $allocationMismatch ? 'persisted_partial' : 'persisted',
                        'allocation_is_actual' => true,
                        'allocated_amount' => $allocatedAmount,
                        'original_allocated_amount' => $originalAllocatedAmount,
                        'non_debt_cash_amount' => max(0.0, $originalAllocatedAmount - $allocatedAmount),
                        'payment_obligation_evidence' => 'purchases.total_amount_minus_debt_amount',
                        'payment_allocation_mismatch' => $allocationMismatch,
                        'needs_manual_review' => $allocationMismatch,
                        'payment_allocation_note' => 'Persisted supplier payment allocation evidence.',
                        'source' => 'document_first',
                    ]));
                }

                $unallocatedAmount = max(0.0, (float) $cf->amount - $actualAllocatedAmount);
                if ($unallocatedAmount <= 0.01) {
                    continue;
                }

                // The remaining real voucher amount is still canonical, but
                // deliberately has no invented purchase allocation.
                $cf = clone $cf;
                $cf->amount = $unallocatedAmount;
            }
            $entries->push($this->createEntry([
                'id' => 'cash_flow-'.$cf->id,
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
                'payment_allocation_confidence' => $hasActualAllocations
                    ? ($allocationMismatch ? 'persisted_partial' : 'persisted')
                    : 'global_payment_only',
                'allocation_is_actual' => false,
                'allocated_amount' => 0.0,
                'payment_allocation_mismatch' => $allocationMismatch,
                'needs_manual_review' => $allocationMismatch || (! $hasActualAllocations
                    && (bool) ($genericPaymentAllocationDiagnostics['has_inferred_allocations'] ?? false)),
                'payment_allocation_note' => $hasActualAllocations
                    ? 'Persisted supplier payment allocation evidence.'
                    : 'No persisted purchase allocation; FIFO is diagnostic only.',
                'source' => 'document_first',
            ]));
        }

        // Reverse each exact payment/allocation effect belonging to a
        // cancelled purchase. One real cash-flow may be allocated to several
        // purchases, so reversal by voucher total would be incorrect.
        foreach ($purchases->filter(fn (Purchase $purchase): bool => BusinessStatus::isCancelled($purchase->status)) as $purchase) {
            $cancelledAt = $purchase->cancelled_at ?: $purchase->updated_at ?: $purchase->purchase_date ?: $purchase->created_at;
            $paymentEntries = $entries->filter(fn (array $entry): bool => str_contains((string) ($entry['event_kind'] ?? ''), 'supplier_payment')
                && (string) ($entry['parent_document_code'] ?? $entry['reference_code'] ?? '') === (string) $purchase->code
                && (float) ($entry['supplier_display_effect'] ?? 0) < -0.01
            );

            foreach ($paymentEntries->values() as $index => $paymentEntry) {
                $amount = abs((float) $paymentEntry['supplier_display_effect']);
                $entries->push($this->createEntry([
                    'id' => 'purchase-payment-cancel-'.$purchase->id.'-'.$index,
                    'code' => 'HUY-TT-'.$purchase->code,
                    'display_type' => 'Hủy thanh toán phiếu nhập',
                    'event_kind' => 'supplier_payment_cancel_reversal',
                    'domain' => 'supplier',
                    'document_amount' => $amount,
                    'amount' => $amount,
                    'display_effect' => $amount,
                    'supplier_display_effect' => $amount,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'reference_type' => 'Purchase',
                    'reference_id' => $purchase->id,
                    'reference_code' => $purchase->code,
                    'parent_document_code' => $purchase->code,
                    'source_table' => 'purchases',
                    'source_id' => $purchase->id.':payment:'.hash('sha256', (string) $paymentEntry['event_identity']),
                    'reversal_of' => $paymentEntry['event_identity'],
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));
            }
        }

        // Standalone supplier receipts are persisted refund/credit evidence.
        // Purchase-return and offset receipts are represented by their source
        // documents below and must not be counted twice here.
        $supplierRefunds = CashFlow::active()
            ->where('target_id', $supplier->id)
            ->whereIn('target_type', PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES)
            ->where('type', 'receipt')
            ->where(function ($query): void {
                $query->whereNull('reference_type')
                    ->orWhereIn('reference_type', ['', 'CashFlow']);
            })
            ->get();
        foreach ($supplierRefunds as $cashFlow) {
            $businessTime = $cashFlow->time ?: $cashFlow->created_at;
            $entries->push($this->createEntry([
                'id' => 'supplier-refund-'.$cashFlow->id,
                'code' => $cashFlow->code,
                'display_type' => 'Hoàn tiền/ghi có NCC',
                'event_kind' => 'supplier_refund',
                'domain' => 'supplier',
                'document_amount' => (float) $cashFlow->amount,
                'amount' => (float) $cashFlow->amount,
                'display_effect' => (float) $cashFlow->amount,
                'supplier_display_effect' => (float) $cashFlow->amount,
                'time' => $businessTime,
                'display_time' => $businessTime,
                'created_at' => $cashFlow->created_at,
                'reference_type' => $cashFlow->reference_type ?: 'CashFlow',
                'reference_id' => $cashFlow->id,
                'reference_code' => $cashFlow->reference_code,
                'source_table' => 'cash_flows',
                'source_id' => $cashFlow->id,
                'detail_available' => true,
                'detail_modal_type' => 'cash_flow',
                'detail_reference_id' => $cashFlow->id,
                'detail_reference_code' => $cashFlow->code,
                'is_real_voucher' => true,
                'is_virtual_fallback' => false,
                'source' => 'document_first',
            ]));
        }

        $cancelledStandaloneCashFlows = CashFlow::withTrashed()
            ->where('target_id', $supplier->id)
            ->whereIn('target_type', PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES)
            ->where(function ($query): void {
                $query->whereNull('reference_type')
                    ->orWhereIn('reference_type', ['', 'CashFlow', 'SupplierPayment']);
            })
            ->get()
            ->filter(fn (CashFlow $cashFlow): bool => $cashFlow->trashed()
                || ! BusinessStatus::isValidCashFlow($cashFlow->status));
        foreach ($cancelledStandaloneCashFlows as $cashFlow) {
            $kind = $cashFlow->type === 'payment' ? 'supplier_payment' : 'supplier_refund';
            $originalDelta = $cashFlow->type === 'payment'
                ? -(float) $cashFlow->amount
                : (float) $cashFlow->amount;
            $originalIdentity = "supplier|cash_flows|{$cashFlow->id}|{$kind}|payable";
            $originalTime = $cashFlow->time ?: $cashFlow->created_at;
            $cancelledAt = $cashFlow->cancelled_at ?: $cashFlow->updated_at ?: $originalTime;
            $common = [
                'code' => $cashFlow->code,
                'domain' => 'supplier',
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
                'display_type' => $kind === 'supplier_payment' ? 'Thanh toán NCC' : 'Hoàn tiền/ghi có NCC',
                'event_kind' => $kind,
                'display_effect' => $originalDelta,
                'supplier_display_effect' => $originalDelta,
                'time' => $originalTime,
                'display_time' => $originalTime,
                'created_at' => $cashFlow->created_at,
                'source_id' => $cashFlow->id,
                'source_status' => 'cancelled',
            ])));
            $entries->push($this->createEntry(array_merge($common, [
                'id' => 'cancelled-cash-flow-reversal-'.$cashFlow->id,
                'display_type' => 'Hủy phiếu '.($cashFlow->type === 'payment' ? 'chi' : 'thu'),
                'event_kind' => $kind.'_cancel_reversal',
                'display_effect' => -$originalDelta,
                'supplier_display_effect' => -$originalDelta,
                'time' => $cancelledAt,
                'display_time' => $cancelledAt,
                'created_at' => $cancelledAt,
                'source_id' => $cashFlow->id.':cancel',
                'source_status' => 'cancelled',
                'reversal_of_event_identity' => $originalIdentity,
            ])));
        }

        // 5. Purchase Returns
        $purchaseReturns = PurchaseReturn::where('supplier_id', $supplier->id)
            ->get()
            ->filter(fn (PurchaseReturn $return) => BusinessStatus::isReturnCompleted($return->status)
                || BusinessStatus::isCancelled($return->status))
            ->values();
        $supplierRefundsByReturn = CashFlow::active()
            ->where('type', 'receipt')
            ->whereIn('reference_type', ['PurchaseReturn', PurchaseReturn::class])
            ->whereIn('reference_code', $purchaseReturns->pluck('code')->filter()->all())
            ->get()
            ->groupBy(fn (CashFlow $cashFlow): string => (string) $cashFlow->reference_code);

        $refundBackedHistoricalPaymentByPurchase = [];
        foreach ($purchaseReturns as $pr) {
            $businessTime = $pr->return_date ?: $pr->created_at;
            $entries->push($this->createEntry([
                'id' => 'purchase_return-'.$pr->id,
                'code' => $pr->code,
                'display_type' => 'Trả hàng nhập',
                'event_kind' => 'purchase_return',
                'domain' => 'supplier',
                'source_status' => $pr->status,
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

            $refundEntries = collect();
            $realRefunds = collect($supplierRefundsByReturn->get((string) $pr->code, []));
            $purchase = $purchasesById->get($pr->purchase_id);
            if ($purchase !== null && BusinessStatus::isReturnCompleted($pr->status)) {
                $purchaseCode = (string) $purchase->code;
                $refundEvidence = max((float) $pr->refund_amount, (float) $realRefunds->sum('amount'));
                $paymentState = (array) ($refundBackedHistoricalPaymentByPurchase[$purchaseCode] ?? [
                    'refund_evidence' => 0.0,
                    'backfilled_payment' => 0.0,
                ]);
                $cumulativeRefundEvidence = (float) $paymentState['refund_evidence'] + max(0.0, $refundEvidence);
                $alreadyBackfilled = (float) $paymentState['backfilled_payment'];
                $requiredHistoricalPayment = min(
                    max(0.0, (float) $purchase->total_amount),
                    $cumulativeRefundEvidence,
                );
                $additionalHistoricalPayment = max(
                    0.0,
                    $requiredHistoricalPayment
                        - $this->purchasePaymentObligation($purchase)
                        - $alreadyBackfilled,
                );

                if ($additionalHistoricalPayment > 0.01) {
                    $purchaseTime = $purchase->purchase_date ?: $purchase->created_at ?: $businessTime;
                    $entries->push($this->createEntry([
                        'id' => 'purchase-return-payment-evidence-'.$pr->id,
                        'code' => 'TTNH-'.($purchase->code ?: $pr->code),
                        'display_type' => 'Thanh toán NCC',
                        'event_kind' => 'supplier_payment_fallback',
                        'domain' => 'supplier',
                        'document_amount' => $additionalHistoricalPayment,
                        'amount' => $additionalHistoricalPayment,
                        'display_effect' => -$additionalHistoricalPayment,
                        'supplier_display_effect' => -$additionalHistoricalPayment,
                        'time' => $purchaseTime,
                        'display_time' => $purchaseTime,
                        'created_at' => $purchase->created_at ?: $purchaseTime,
                        'reference_type' => 'Purchase',
                        'reference_id' => $purchase->id,
                        'reference_code' => $purchase->code,
                        'parent_document_code' => $purchase->code,
                        'source_table' => 'purchase_returns',
                        'source_id' => $pr->id.':historical-payment-evidence',
                        'is_real_voucher' => false,
                        'is_virtual_fallback' => true,
                        'fallback_for_unallocated_amount' => true,
                        'persisted_evidence' => 'purchase_returns.refund_amount',
                        'refund_evidence_amount' => $refundEvidence,
                        'source' => 'purchase_return_refund_payment_evidence',
                    ]));
                }
                $refundBackedHistoricalPaymentByPurchase[$purchaseCode] = [
                    'refund_evidence' => $cumulativeRefundEvidence,
                    'backfilled_payment' => $alreadyBackfilled + $additionalHistoricalPayment,
                ];
            }

            foreach ($realRefunds as $cashFlow) {
                $refundAmount = (float) $cashFlow->amount;
                if ($refundAmount <= 0.01) {
                    continue;
                }

                $refundEntry = $this->createEntry([
                    'id' => 'purchase-return-refund-'.$cashFlow->id,
                    'code' => $cashFlow->code,
                    'display_type' => 'NCC hoàn tiền trả hàng',
                    'event_kind' => 'supplier_refund',
                    'domain' => 'supplier',
                    'document_amount' => $refundAmount,
                    'amount' => $refundAmount,
                    'display_effect' => $refundAmount,
                    'supplier_display_effect' => $refundAmount,
                    'time' => $cashFlow->time ?: $cashFlow->created_at,
                    'display_time' => $cashFlow->time ?: $cashFlow->created_at,
                    'created_at' => $cashFlow->created_at,
                    'reference_type' => 'PurchaseReturn',
                    'reference_id' => $pr->id,
                    'reference_code' => $pr->code,
                    'parent_document_code' => $pr->code,
                    'detail_available' => true,
                    'detail_modal_type' => 'cash_flow',
                    'detail_reference_id' => $cashFlow->id,
                    'detail_reference_code' => $cashFlow->code,
                    'source_table' => 'cash_flows',
                    'source_id' => (string) $cashFlow->id,
                    'badge_label' => 'Hoàn tiền',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]);
                $entries->push($refundEntry);
                $refundEntries->push($refundEntry);
            }

            // The persisted refund_amount represents only the portion without
            // a surviving real cash-flow. It is evidence-backed fallback, not
            // a second copy of the voucher.
            $uncoveredRefund = max(0.0, (float) $pr->refund_amount - (float) $realRefunds->sum('amount'));
            if ($uncoveredRefund > 0.01) {
                $refundEntry = $this->createEntry([
                    'id' => 'purchase-return-refund-fallback-'.$pr->id,
                    'code' => 'HTNCC-'.$pr->code,
                    'display_type' => 'NCC hoàn tiền trả hàng',
                    'event_kind' => 'supplier_refund_fallback',
                    'domain' => 'supplier',
                    'document_amount' => $uncoveredRefund,
                    'amount' => $uncoveredRefund,
                    'display_effect' => $uncoveredRefund,
                    'supplier_display_effect' => $uncoveredRefund,
                    'time' => $businessTime,
                    'display_time' => $businessTime,
                    'created_at' => $pr->created_at,
                    'reference_type' => 'PurchaseReturn',
                    'reference_id' => $pr->id,
                    'reference_code' => $pr->code,
                    'parent_document_code' => $pr->code,
                    'source_table' => 'purchase_returns',
                    'source_id' => $pr->id.':refund-fallback',
                    'badge_label' => 'Tạm tính',
                    'is_real_voucher' => false,
                    'is_virtual_fallback' => true,
                    'fallback_for_unallocated_amount' => true,
                    'real_refund_covered_amount' => (float) $realRefunds->sum('amount'),
                    'source' => 'legacy_purchase_return_refund_amount',
                ]);
                $entries->push($refundEntry);
                $refundEntries->push($refundEntry);
            }

            if (BusinessStatus::isCancelled($pr->status)) {
                $cancelledAt = $pr->updated_at ?: $businessTime;
                $entries->push($this->createEntry([
                    'id' => 'purchase-return-cancel-'.$pr->id,
                    'code' => 'HUY-'.$pr->code,
                    'display_type' => 'Hủy trả hàng nhập',
                    'event_kind' => 'purchase_return_cancel_reversal',
                    'domain' => 'supplier',
                    'document_amount' => (float) $pr->total_amount,
                    'amount' => (float) $pr->total_amount,
                    'display_effect' => (float) $pr->total_amount,
                    'supplier_display_effect' => (float) $pr->total_amount,
                    'time' => $cancelledAt,
                    'display_time' => $cancelledAt,
                    'created_at' => $cancelledAt,
                    'reference_type' => 'PurchaseReturn',
                    'reference_id' => $pr->id,
                    'reference_code' => $pr->code,
                    'source_table' => 'purchase_returns',
                    'source_id' => (string) $pr->id,
                    'reversal_of' => 'supplier|purchase_returns|'.$pr->id.'|purchase_return|payable',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ]));

                foreach ($refundEntries->values() as $index => $refundEntry) {
                    $refundAmount = abs((float) $refundEntry['supplier_display_effect']);
                    $entries->push($this->createEntry([
                        'id' => 'purchase-return-refund-cancel-'.$pr->id.'-'.$index,
                        'code' => 'HUY-HT-'.$pr->code,
                        'display_type' => 'Hủy hoàn tiền trả hàng nhập',
                        'event_kind' => 'supplier_refund_cancel_reversal',
                        'domain' => 'supplier',
                        'document_amount' => $refundAmount,
                        'amount' => -$refundAmount,
                        'display_effect' => -$refundAmount,
                        'supplier_display_effect' => -$refundAmount,
                        'time' => $cancelledAt,
                        'display_time' => $cancelledAt,
                        'created_at' => $cancelledAt,
                        'reference_type' => 'PurchaseReturn',
                        'reference_id' => $pr->id,
                        'reference_code' => $pr->code,
                        'source_table' => 'purchase_returns',
                        'source_id' => $pr->id.':refund:'.hash('sha256', (string) $refundEntry['event_identity']),
                        'reversal_of' => $refundEntry['event_identity'],
                        'is_real_voucher' => true,
                        'is_virtual_fallback' => false,
                        'source' => 'document_first',
                    ]));
                }
            }
        }

        // 6. Supplier Debt Transactions (adjustments, offsets, discounts)
        // DebtOffset is the business document. Any ledger row carrying the
        // same voucher code is supporting evidence and must not be reduced a
        // second time.
        $offsets = $this->debtOffsetsForPartner((int) $supplier->id);
        $offsetCodes = $offsets->pluck('code')->filter()->map(fn ($code) => (string) $code)->all();
        $offsetCancellationLedgerCodes = CashFlow::active()
            ->where('target_id', $supplier->id)
            ->where('type', 'payment')
            ->where('reference_type', 'DebtOffsetCancel')
            ->whereIn('reference_code', $offsetCodes)
            ->pluck('code')
            ->filter()
            ->map(fn ($code) => (string) $code)
            ->all();
        $supplierDebts = SupplierDebtTransaction::where('supplier_id', $supplier->id)->get();

        foreach ($supplierDebts as $stx) {
            $refCode = $stx->code;

            if ($this->supplierLedgerPaymentIsDocumentEvidence($stx, $purchases, $supplierPayments)) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $stx->amount,
                    'reason' => 'supplier_payment_ledger_mirror_excluded',
                    'source' => 'supplier_debt_transactions',
                ];

                continue;
            }

            $isDocumentMirror = $refCode && (
                in_array((string) $refCode, $offsetCodes, true)
                || in_array((string) $refCode, $offsetCancellationLedgerCodes, true)
            );
            $isTech = $this->isTechnicalLedgerCode($refCode) || $isDocumentMirror;
            if ($isTech) {
                $excludedLedgerEntries[] = [
                    'code' => $refCode,
                    'amount' => (float) $stx->amount,
                    'reason' => $isDocumentMirror
                        ? 'debt_offset_ledger_mirror_excluded'
                        : 'technical_ledger_excluded_from_document_timeline',
                    'source' => 'supplier_debt_transactions',
                ];

                if (! $includeTechnical) {
                    continue;
                }
            }
            $businessTime = Schema::hasColumn('supplier_debt_transactions', 'recorded_at')
                ? ($stx->recorded_at ?? $stx->created_at)
                : $stx->created_at;

            [$displayType, $eventKind, $badgeLabel] = $this->classifySupplierDebt($stx);

            $entries->push($this->createEntry([
                'id' => 'supplier_debt-'.$stx->id,
                'code' => $refCode ?: ('DC'.$stx->id),
                'display_type' => $displayType,
                'event_kind' => $eventKind,
                'domain' => 'supplier',
                'document_amount' => abs((float) $stx->amount),
                'amount' => (float) $stx->amount,
                'display_effect' => (float) $stx->amount,
                'supplier_display_effect' => $isTech ? 0.0 : (float) $stx->amount,
                'affects_document_balance' => ! $isTech,
                'excluded_from_document_balance' => $isTech,
                'excluded_reason' => $isDocumentMirror
                    ? 'debt_offset_document_is_canonical'
                    : ($isTech ? 'technical_ledger_merge_or_opening' : null),
                'affects_canonical_balance' => ! $isTech,
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
        $offsets = $offsets->values();

        foreach ($offsets as $offset) {
            $isReversal = (int) ($offset->reverses_debt_offset_id ?? 0) > 0;
            $offsetEffect = ($isReversal ? 1.0 : -1.0) * (float) $offset->amount;
            $entries->push($this->createEntry([
                'id' => 'offset-'.$offset->id,
                'code' => $offset->code,
                'display_type' => 'Điều chỉnh',
                'event_kind' => $isReversal ? 'debt_offset_reversal' : 'debt_offset',
                'domain' => 'supplier',
                'source_status' => $offset->status,
                'document_amount' => (float) $offset->amount,
                'amount' => (float) $offset->amount,
                'display_effect' => $offsetEffect,
                'supplier_display_effect' => $offsetEffect,
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
                ->get()
                ->reject(fn (Invoice $invoice) => BusinessStatus::isCancelled($invoice->status))
                ->values();
            $invoiceCodes = $invoices->pluck('code')->filter()->toArray();

            foreach ($invoices as $invoice) {
                $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cust-invoice-'.$invoice->id,
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
                ->whereIn('target_type', PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES)
                ->get();

            $receiptsByInvoice = [];
            foreach ($customerReceipts as $cf) {
                $refCode = $cf->reference_code;
                if ($cf->reference_type === 'Invoice' && $refCode && in_array($refCode, $invoiceCodes, true)) {
                    $receiptsByInvoice[$refCode][] = $cf;
                }

                $businessTime = $cf->time ?: $cf->created_at;
                $referenceType = class_basename(trim((string) ($cf->reference_type ?? '')));
                $isCustomerDebtAdjustment = strcasecmp($referenceType, 'DebtAdjustment') === 0
                    || trim((string) ($cf->category ?? '')) === 'Điều chỉnh công nợ';

                $receiptEntry = [
                    'id' => 'cust-receipt-'.$cf->id,
                    'code' => $cf->code,
                    'display_type' => $isCustomerDebtAdjustment ? 'Điều chỉnh công nợ' : 'Khách thanh toán',
                    'event_kind' => $isCustomerDebtAdjustment ? 'customer_debt_adjustment' : 'invoice_payment',
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
                    'badge_label' => $isCustomerDebtAdjustment ? 'Điều chỉnh' : 'Thanh toán',
                    'badge_title' => $isCustomerDebtAdjustment
                        ? ($cf->description ?: 'Phiếu điều chỉnh công nợ khách hàng')
                        : 'Khách hàng thanh toán công nợ',
                    'is_real_voucher' => true,
                    'is_virtual_fallback' => false,
                    'source' => 'document_first',
                ];

                $entries->push($this->createEntry($receiptEntry));
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
                        if ($cf->code === 'TTHD'.preg_replace('/^HD/', '', $invoice->code)) {
                            $hasRealReceipt = true;
                            break;
                        }
                    }
                    if (! $hasRealReceipt) {
                        $businessTime = $invoice->transaction_date ?: $invoice->created_at;
                        $entries->push($this->createEntry([
                            'id' => 'cust-invpay-fallback-'.$invoice->id,
                            'code' => 'TTHD'.preg_replace('/^HD/', '', $invoice->code),
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
                ->get()
                ->reject(fn (OrderReturn $return) => BusinessStatus::isCancelled($return->status))
                ->values();

            foreach ($orderReturns as $or) {
                $businessTime = ($or->return_date ?? null) ?: $or->created_at;
                $entries->push($this->createEntry([
                    'id' => 'cust-return-'.$or->id,
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
                        'id' => 'cust-refund-fallback-'.$or->id,
                        'code' => 'PCTH'.preg_replace('/^TH/', '', $or->code),
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
                $isOffsetMirror = $refCode && in_array((string) $refCode, $offsetCodes, true);
                $isDocumentMirror = $isOffsetMirror || $this->customerLedgerIsDocumentEvidence(
                    $debt,
                    $invoices,
                    $customerReceipts,
                    $orderReturns,
                );
                $isTech = $this->isTechnicalLedgerCode($refCode) || $isDocumentMirror;
                if ($isTech) {
                    $excludedLedgerEntries[] = [
                        'code' => $refCode,
                        'amount' => (float) $debt->amount,
                        'reason' => $isOffsetMirror
                            ? 'debt_offset_ledger_mirror_excluded'
                            : ($isDocumentMirror
                                ? 'customer_document_ledger_mirror_excluded'
                                : 'technical_ledger_excluded_from_document_timeline'),
                        'source' => 'customer_debts',
                    ];

                    if (! $includeTechnical) {
                        continue;
                    }
                }

                $businessTime = $debt->recorded_at ?: $debt->created_at;
                [$displayType, $eventKind, $badgeLabel] = $this->classifyCustomerDebt($debt);

                $entries->push($this->createEntry([
                    'id' => 'customer_debt-'.$debt->id,
                    'code' => $refCode ?: ('DC'.$debt->id),
                    'display_type' => $displayType,
                    'event_kind' => $eventKind,
                    'domain' => 'customer',
                    'document_amount' => abs((float) $debt->amount),
                    'amount' => (float) $debt->amount,
                    'display_effect' => -(float) $debt->amount,
                    'supplier_display_effect' => $isTech ? 0.0 : -(float) $debt->amount,
                    'affects_document_balance' => ! $isTech,
                    'excluded_from_document_balance' => $isTech,
                    'excluded_reason' => $isOffsetMirror
                        ? 'debt_offset_document_is_canonical'
                        : ($isDocumentMirror
                            ? 'customer_document_is_canonical'
                            : ($isTech ? 'technical_ledger_merge_or_opening' : null)),
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

            // Customer Offsets (CB / HCB)
            $customerOffsets = $offsets;
            foreach ($customerOffsets as $offset) {
                $entries->push($this->createEntry([
                    'id' => 'cust-offset-'.$offset->id,
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

                if ($offset->status === 'cancelled' && ! $offset->reversalVoucher()->exists()) {
                    $cancelCode = 'HCB'.str_pad($offset->id, 6, '0', STR_PAD_LEFT);
                    $entries->push($this->createEntry([
                        'id' => 'cust-offset-cancel-'.$offset->id,
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

        if ((bool) ($options['canonical'] ?? false)) {
            $entries = $this->addPersistedLedgerCheckpoints(
                $entries,
                $supplierDebts,
                $purchases,
                $supplierPayments,
                $offsets,
                $offsetCancellationLedgerCodes,
            );
        }

        // Deduplicate only the canonical source identity. Voucher codes are
        // display labels and can collide between independent source tables.
        $deduped = [];
        foreach ($entries as $entry) {
            $identity = (string) ($entry['event_identity'] ?? $entry['id']);
            if (! isset($deduped[$identity])) {
                $deduped[$identity] = $entry;
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

        // Stored projection is comparison evidence only. It must never shift
        // timeline balances or create a synthetic canonical opening event.
        $storedCustomerDebt = (float) ($supplier->debt_amount ?? 0);
        $storedSupplierDebt = $hasSupplierColumn ? (float) ($supplier->supplier_debt_amount ?? 0) : 0.0;

        if ($usePartnerTimeline) {
            $storedBalance = $storedSupplierDebt - $storedCustomerDebt;
            $balanceLabel = 'Nợ cần trả nhà cung cấp';
        } else {
            $storedBalance = $storedSupplierDebt;
            $balanceLabel = 'Nợ cần trả nhà cung cấp';
        }

        $rawDocumentFinalBalance = $documentFinalBalance;
        $displayFinalBalance = $rawDocumentFinalBalance;
        $difference = $rawDocumentFinalBalance - $storedBalance;
        $rawMismatch = abs($difference) > 1.0;
        $hasInferredGenericAllocations = (bool) ($genericPaymentAllocationDiagnostics['has_inferred_allocations'] ?? false);
        $hasUnallocatedGenericPayments = (bool) ($genericPaymentAllocationDiagnostics['has_unallocated_generic_payments'] ?? false);
        $isMismatch = $rawMismatch;

        $severity = 'ok';
        $message = null;
        if ($isMismatch) {
            $severity = 'warning';
            $message = 'Timeline chứng từ lệch với Nợ hiện tại. Cần đối soát dữ liệu, chưa tự sửa.';
        }
        if (! $isMismatch && $hasInferredGenericAllocations) {
            $severity = 'warning';
            $message = 'Số dư tổng đã khớp, nhưng phân bổ phiếu thanh toán công nợ tổng quát theo từng phiếu nhập chỉ là suy luận từ dữ liệu lịch sử. Cần đối soát nếu trước đây đã phân bổ thủ công.';
        }
        if (! $isMismatch && $hasUnallocatedGenericPayments) {
            $severity = 'warning';
            $message = 'Có phiếu thanh toán công nợ tổng quát chưa thể đối chiếu an toàn với phiếu nhập. Timeline giữ dữ liệu gốc và không tự sửa.';
        }

        return [
            'entries' => $displayEntries,
            'summary' => [
                'current_debt' => $storedBalance,
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
                'net_debt_amount' => $storedCustomerDebt - $storedSupplierDebt,
                'net' => $storedBalance,
                'display_balance_target' => $storedBalance,
                'display_balance_final' => $displayFinalBalance,
                'display_alignment_amount' => 0.0,
                'display_aligned' => false,
                'has_virtual_display_alignment' => false,
                'display_mode' => $usePartnerTimeline ? 'supplier_partner_timeline' : 'supplier_payable',
                'is_supplier_tab_partner_timeline' => $usePartnerTimeline,
                'balance_label' => $balanceLabel,
                'has_virtual_opening_balance' => false,
                'virtual_opening_balance' => 0.0,
            ],
            'reconcile' => [
                'severity' => $severity,
                'message' => $message,
                'user_warning' => $isMismatch || $hasInferredGenericAllocations || $hasUnallocatedGenericPayments,
                'stored_balance' => $storedBalance,
                'document_balance' => $rawDocumentFinalBalance,
                'raw_document_balance' => $rawDocumentFinalBalance,
                'difference' => $difference,
                'allocation_confidence' => $hasInferredGenericAllocations ? 'inferred' : 'actual_or_legacy',
                'has_inferred_generic_allocations' => $hasInferredGenericAllocations,
                'has_unallocated_generic_payments' => $hasUnallocatedGenericPayments,
                'generic_payment_allocation' => $genericPaymentAllocationDiagnostics,
                // Alignment keys
                'computed_balance' => $rawDocumentFinalBalance,
                'has_mismatch' => $isMismatch,
                'raw_has_mismatch' => $rawMismatch,
                'ledger_mismatch' => $rawMismatch,
                'display_resolved' => ! $isMismatch && ! $hasInferredGenericAllocations && ! $hasUnallocatedGenericPayments,
                'display_balance_target' => $storedBalance,
                'display_balance_final' => $displayFinalBalance,
                'display_alignment_amount' => 0.0,
                'display_aligned' => false,
                'has_virtual_display_alignment' => false,
                'excluded_ledger_entries' => $excludedLedgerEntries,
            ],
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
            ->filter(fn (Purchase $purchase) => $this->purchasePaymentObligation($purchase) > 0.01)
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
                $paidAmount = $this->purchasePaymentObligation($purchase);
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
            foreach ($genericPayments as $payment) {
                $amount = (float) $payment->amount;

                if ($amount <= 0.01) {
                    continue;
                }

                $diagnostics['has_unallocated_generic_payments'] = true;
                $diagnostics['unallocated_generic_payments'][] = [
                    'payment_code' => $payment->code,
                    'amount' => $amount,
                    'allocation_confidence' => 'unknown',
                    'allocation_is_actual' => false,
                    'reason' => 'no_eligible_purchase_paid_snapshot_at_or_before_payment_time',
                ];
            }

            if ($diagnostics['has_unallocated_generic_payments']) {
                $diagnostics['warnings'][] = 'generic_supplier_payment_has_unallocated_residual';
            }

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

    /**
     * Persisted payment evidence for a purchase.
     *
     * paid_amount can include acquisition costs that never mutate supplier
     * payable. The persisted purchase obligation is the net document amount
     * after the document discount, less the remaining debt; a negative debt
     * intentionally permits an overpayment credit. Older rows which never
     * persisted a positive remaining debt are capped by their smaller
     * paid_amount instead.
     */
    private function purchasePaymentObligation(Purchase $purchase): float
    {
        $total = max(0.0, (float) $purchase->total_amount);
        $discount = max(0.0, (float) ($purchase->discount ?? 0));
        $netTotal = max(0.0, $total - $discount);
        $debt = (float) $purchase->debt_amount;
        $paid = max(0.0, (float) $purchase->paid_amount);
        if (abs($debt) <= 0.01 && $paid < $netTotal - 0.01) {
            return $paid;
        }

        return max(0.0, $netTotal - $debt);
    }

    /**
     * Reconstruct discontinuities from persisted supplier ledger checkpoints.
     *
     * Legacy imports and backdated documents can make document creation order
     * diverge from business timestamps. A non-purchase ledger row persists the
     * authoritative debt_remain immediately after that mutation. When the
     * preceding canonical events do not reach its persisted before-state, emit
     * a signed checkpoint event backed by that exact row. This is neither a
     * virtual opening nor a stored customer projection event.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  Collection<int, SupplierDebtTransaction>  $transactions
     * @return Collection<int, array<string, mixed>>
     */
    private function addPersistedLedgerCheckpoints(
        Collection $entries,
        Collection $transactions,
        Collection $purchases,
        Collection $cashFlows,
        Collection $offsets,
        array $offsetCancellationLedgerCodes,
    ): Collection {
        $timeline = $entries
            ->filter(fn (array $entry): bool => (string) ($entry['domain'] ?? '') === 'supplier'
                && (bool) ($entry['affects_document_balance'] ?? true))
            ->map(fn (array $entry): array => [
                'kind' => 'event',
                'time' => $this->normalizeSortableTime($entry['created_at'] ?? $entry['time'] ?? null),
                'order' => 0,
                'key' => (string) ($entry['event_identity'] ?? $entry['id'] ?? ''),
                'entry' => $entry,
            ]);

        $markers = $transactions
            ->filter(fn (SupplierDebtTransaction $transaction): bool => (int) ($transaction->purchase_id ?? 0) === 0)
            ->reject(fn (SupplierDebtTransaction $transaction): bool => $this->supplierLedgerPaymentIsDocumentEvidence(
                $transaction,
                $purchases,
                $cashFlows,
            ))
            ->reject(fn (SupplierDebtTransaction $transaction): bool => $this->supplierLedgerOffsetIsDocumentEvidence(
                $transaction,
                $offsets,
                $offsetCancellationLedgerCodes,
            ))
            ->map(fn (SupplierDebtTransaction $transaction): array => [
                'kind' => 'checkpoint',
                'time' => $this->normalizeSortableTime($transaction->created_at),
                'order' => 1,
                'key' => str_pad((string) $transaction->id, 20, '0', STR_PAD_LEFT),
                'transaction' => $transaction,
            ]);

        $running = 0.0;
        $checkpoints = collect();
        $timeline
            ->concat($markers)
            ->sort(function (array $left, array $right): int {
                return [$left['time'], $left['order'], $left['key']]
                    <=> [$right['time'], $right['order'], $right['key']];
            })
            ->each(function (array $item) use (&$running, $checkpoints): void {
                if ($item['kind'] === 'event') {
                    $entry = $item['entry'];
                    $running += (float) ($entry['supplier_display_effect']
                        ?? $entry['display_effect']
                        ?? $entry['amount']
                        ?? 0);

                    return;
                }

                /** @var SupplierDebtTransaction $transaction */
                $transaction = $item['transaction'];
                $expected = (float) $transaction->debt_remain;
                $correction = $expected - $running;
                if (abs($correction) <= 0.01) {
                    return;
                }

                $checkpoints->push($this->createEntry([
                    'id' => 'supplier-ledger-checkpoint-'.$transaction->id,
                    'code' => 'CHECKPOINT-'.($transaction->code ?: $transaction->id),
                    'display_type' => 'Đối chiếu lịch sử',
                    'event_kind' => 'persisted_ledger_checkpoint',
                    'domain' => 'supplier',
                    'document_amount' => abs($correction),
                    'amount' => $correction,
                    'display_effect' => $correction,
                    'supplier_display_effect' => $correction,
                    'time' => $transaction->created_at,
                    'display_time' => $transaction->created_at,
                    'created_at' => $transaction->created_at,
                    'reference_type' => 'SupplierDebtTransaction',
                    'reference_id' => $transaction->id,
                    'reference_code' => $transaction->code,
                    'source_table' => 'supplier_debt_transactions',
                    'source_id' => $transaction->id.':checkpoint',
                    'source_status' => 'persisted_evidence',
                    'detail_available' => false,
                    'detail_modal_type' => 'none',
                    'badge_label' => 'Không phải phiếu',
                    'badge_title' => 'Đây là dòng đối chiếu lịch sử, không phải phiếu giao dịch. Số tiền được suy ra từ debt_remain đã lưu trên sổ công nợ.',
                    'is_real_voucher' => false,
                    'is_reconciliation_checkpoint' => true,
                    'is_virtual_fallback' => false,
                    'persisted_evidence' => 'supplier_debt_transactions.debt_remain',
                    'persisted_debt_remain' => $expected,
                    'canonical_running_before_checkpoint' => $running,
                    'source' => 'persisted_ledger_checkpoint',
                ]));
                $running = $expected;
            });

        return $entries->concat($checkpoints)->values();
    }

    private function supplierLedgerOffsetIsDocumentEvidence(
        SupplierDebtTransaction $transaction,
        Collection $offsets,
        array $offsetCancellationLedgerCodes,
    ): bool {
        $code = (string) ($transaction->code ?? '');
        if ($code === '' || ! in_array((string) $transaction->type, ['offset', 'adjustment'], true)) {
            return false;
        }

        return $offsets->contains(fn (DebtOffset $offset): bool => (string) ($offset->code ?? '') === $code)
            || in_array($code, $offsetCancellationLedgerCodes, true);
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
        $eventKind = 'supplier_'.$type;
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
        $entry = array_merge([
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
        $effectSide = (string) ($entry['effect_side'] ?? ($entry['domain'] === 'customer' ? 'receivable' : 'payable'));
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

    private function sourceTableForReference(?string $referenceType): string
    {
        return match ((string) $referenceType) {
            'Invoice' => 'invoices',
            'OrderReturn' => 'order_returns',
            'Purchase' => 'purchases',
            'PurchaseReturn' => 'purchase_returns',
            'CustomerDebt' => 'customer_debts',
            'SupplierDebtTransaction' => 'supplier_debt_transactions',
            'DebtOffset', 'DebtOffsetCancel' => 'debt_offsets',
            default => 'cash_flows',
        };
    }

    private function supplierLedgerPaymentIsDocumentEvidence(
        SupplierDebtTransaction $transaction,
        Collection $purchases,
        Collection $cashFlows,
    ): bool {
        $type = (string) $transaction->type;
        $code = (string) ($transaction->code ?? '');
        $purchaseById = (int) ($transaction->purchase_id ?? 0) > 0
            ? $purchases->firstWhere('id', (int) $transaction->purchase_id)
            : null;
        if ($purchaseById && in_array($type, ['purchase', 'payment'], true)) {
            return true;
        }
        if ($type === 'purchase' && $code !== '' && $purchases->contains(
            fn (Purchase $purchase): bool => (string) $purchase->code === $code,
        )) {
            return true;
        }
        if ($type !== 'payment') {
            // The cancellation audit row mirrors the cancelled PCPN. The
            // cash-flow source emits the exact original + reversal pair.
            return $type === 'payment_cancel' && str_starts_with($code, 'HPCPN');
        }

        if ($code !== '' && $cashFlows->contains(fn (CashFlow $cashFlow) => (string) $cashFlow->code === $code || (string) $cashFlow->reference_code === $code
        )) {
            return true;
        }

        $purchaseCode = str_starts_with($code, 'PCPN')
            ? 'PN'.substr($code, 4)
            : '';
        if ($purchaseCode === '') {
            return false;
        }

        $purchase = $purchases->firstWhere('code', $purchaseCode);

        return $purchase !== null && (float) ($purchase->paid_amount ?? 0) > 0.01;
    }

    private function customerLedgerIsDocumentEvidence(
        CustomerDebt $debt,
        Collection $invoices,
        Collection $cashFlows,
        Collection $orderReturns,
    ): bool {
        $code = (string) ($debt->ref_code ?? '');
        if ($code === '') {
            return false;
        }

        return match ((string) $debt->type) {
            'sale' => $invoices->contains(fn (Invoice $invoice): bool => (string) $invoice->code === $code),
            'payment' => $cashFlows->contains(fn (CashFlow $cashFlow): bool => (string) $cashFlow->code === $code
                || (string) $cashFlow->reference_code === $code
            ) || $invoices->contains(fn (Invoice $invoice): bool => $code === 'TTHD'.preg_replace('/^HD/', '', (string) $invoice->code)
            ),
            'return' => $orderReturns->contains(fn (OrderReturn $return): bool => (string) $return->code === $code),
            default => false,
        };
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

            if (
                $eventKind === 'invoice_payment'
                && (bool) ($entry['is_real_voucher'] ?? false)
                && ! (bool) ($entry['is_virtual_fallback'] ?? false)
            ) {
                $entry['badge_label'] = 'Thanh toán';
            }
        } else {
            $entry['supplier_balance_effect'] = (float) ($entry['supplier_balance_effect'] ?? $entry['supplier_display_balance_effect']);
            $entry['affects_debt_balance'] = $entry['affects_debt_balance'] ?? true;
        }

        $entry['partner_effect'] = $entry['partner_effect'] ?? $effect;
        $entry['supplier_partner_effect'] = $entry['supplier_partner_effect'] ?? $effect;
        $entry['source_ledger'] = $entry['source_ledger'] ?? $this->compatibilitySourceLedger($entry);

        return $entry;
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

        if (! $createdAt || ! $displayTime) {
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
