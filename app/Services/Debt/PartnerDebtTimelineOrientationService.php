<?php

namespace App\Services\Debt;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Projects one canonical event stream into the two KiotViet screen signs.
 */
class PartnerDebtTimelineOrientationService
{
    private const TOLERANCE = 1.0;

    public function __construct(private readonly CanonicalPartnerDebtEventService $events) {}

    public function customer(Customer $partner, array $options = []): array
    {
        return $this->orient($partner, 'customer', $options);
    }

    public function supplier(Customer $partner, array $options = []): array
    {
        return $this->orient($partner, 'supplier', $options);
    }

    private function orient(Customer $partner, string $orientation, array $options): array
    {
        $role = PartnerDebtRoleResolver::integrity($partner);
        $isCustomer = (bool) $role['persisted_customer'];
        $isSupplier = (bool) $role['persisted_supplier'];
        $isDualRole = $isCustomer && $isSupplier;
        $applicable = $orientation === 'customer' ? $isCustomer : $isSupplier;
        $canonical = empty($options)
            ? $this->events->build($partner)
            : $this->events->build($partner, $options);
        $applicableEvents = $applicable
            ? $this->applicableEvents($canonical, $isDualRole, $orientation)
            : collect();

        $storedCustomer = (float) ($partner->debt_amount ?? 0);
        $storedSupplier = (float) ($partner->supplier_debt_amount ?? 0);
        $canonicalCustomer = (float) $canonical
            ->where('affects_balance', true)
            ->sum('customer_delta');
        $canonicalSupplier = (float) $canonical
            ->where('affects_balance', true)
            ->sum('supplier_delta');

        $target = $this->target(
            $orientation,
            $isDualRole,
            $applicable,
            $storedCustomer,
            $storedSupplier,
        );

        [$displayEntries, $rawFinal] = $this->displayEntries($applicableEvents, $orientation);
        $excludedLedgerEntries = $this->excludedLedgerEntries($applicableEvents);
        $difference = $rawFinal - $target;
        $hasMismatch = $applicable && abs($difference) > self::TOLERANCE;
        $identityHash = $this->events->identityHash($applicableEvents);
        $displayMode = $isDualRole
            ? ($orientation === 'customer' ? 'partner_net_timeline' : 'supplier_partner_timeline')
            : ($orientation === 'customer' ? 'customer_receivable' : 'supplier_payable');
        $balanceLabel = $orientation === 'customer'
            ? 'Dư nợ khách hàng / Công nợ'
            : 'Nợ cần trả nhà cung cấp';

        $contract = [
            'orientation' => $orientation,
            'applicable' => $applicable,
            'persisted_role' => $role['persisted_role'],
            'effective_role' => $role['effective_role'],
            'evidence_role' => $role['evidence_role'],
            'role_integrity_status' => $role['role_integrity_status'],
            'customer_receivable' => $storedCustomer,
            'supplier_payable' => $storedSupplier,
            'canonical_customer_receivable' => $canonicalCustomer,
            'canonical_supplier_payable' => $canonicalSupplier,
            'target_balance' => $target,
            'raw_final_balance' => $rawFinal,
            'difference' => $difference,
            'has_mismatch' => $hasMismatch,
            'canonical_entry_count' => $applicableEvents->count(),
            'entry_count' => $displayEntries->count(),
            'source_identity_hash' => $identityHash,
        ];

        $summary = array_merge($contract, [
            'current_debt' => $target,
            'stored_customer_debt' => $storedCustomer,
            'stored_supplier_debt' => $storedSupplier,
            'document_final_balance' => $rawFinal,
            'raw_document_final_balance' => $rawFinal,
            'document_final_balance_before_alignment' => $rawFinal,
            'is_dual_role' => $isDualRole,
            'mode' => 'canonical_partner_debt_events',
            'source' => CanonicalPartnerDebtEventService::CONTRACT_VERSION,
            'count' => $displayEntries->count(),
            'customer_debt_amount' => $storedCustomer,
            'supplier_debt_amount' => $storedSupplier,
            'net_debt_amount' => $storedCustomer - $storedSupplier,
            'net' => $target,
            'display_balance_target' => $target,
            'display_balance_final' => $rawFinal,
            'display_mode' => $displayMode,
            'is_supplier_tab_partner_timeline' => $orientation === 'supplier' && $isDualRole,
            'balance_label' => $balanceLabel,
            'display_alignment_amount' => 0.0,
            'display_aligned' => false,
            'has_virtual_display_alignment' => false,
            'has_virtual_opening_balance' => false,
            'virtual_opening_balance' => 0.0,
        ]);

        $reconcile = [
            'severity' => $hasMismatch ? 'warning' : 'ok',
            'message' => $hasMismatch
                ? 'Timeline chứng từ lệch với số dư mục tiêu. Cần đối soát dữ liệu, chưa tự sửa.'
                : null,
            'user_warning' => $hasMismatch,
            'stored_balance' => $target,
            'document_balance' => $rawFinal,
            'raw_document_balance' => $rawFinal,
            'difference' => $difference,
            'computed_balance' => $rawFinal,
            'has_mismatch' => $hasMismatch,
            'raw_has_mismatch' => $hasMismatch,
            'ledger_mismatch' => $hasMismatch,
            'display_resolved' => ! $hasMismatch,
            'display_balance_target' => $target,
            'display_balance_final' => $rawFinal,
            'display_alignment_amount' => 0.0,
            'display_aligned' => false,
            'has_virtual_display_alignment' => false,
            'has_virtual_opening_balance' => false,
            'role_integrity_status' => $role['role_integrity_status'],
            'role_integrity_warning' => (bool) $role['has_role_integrity_mismatch'],
            'excluded_ledger_entries' => $excludedLedgerEntries,
        ];

        return array_merge($contract, [
            'entries' => $displayEntries,
            'summary' => $summary,
            'reconcile' => $reconcile,
            'role_integrity' => $role,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private function applicableEvents(Collection $events, bool $isDualRole, string $orientation): Collection
    {
        if ($isDualRole) {
            return $events->values();
        }

        $side = $orientation === 'customer' ? 'customer_delta' : 'supplier_delta';
        $domain = $orientation;

        return $events
            ->filter(fn (array $event): bool => (string) ($event['domain'] ?? '') === $domain
                || abs((float) ($event[$side] ?? 0)) > 0.0001)
            ->values();
    }

    private function target(
        string $orientation,
        bool $isDualRole,
        bool $applicable,
        float $customerReceivable,
        float $supplierPayable,
    ): float {
        if (! $applicable) {
            return 0.0;
        }
        if ($isDualRole) {
            return $orientation === 'customer'
                ? $customerReceivable - $supplierPayable
                : $supplierPayable - $customerReceivable;
        }

        return $orientation === 'customer' ? $customerReceivable : $supplierPayable;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array{Collection<int, array<string, mixed>>, float}
     */
    private function displayEntries(Collection $events, string $orientation): array
    {
        // The canonical reducer deliberately keeps every persisted allocation
        // event.  The partner screen, however, is a document view: one real
        // supplier-payment voucher must render once even when it allocates to
        // many purchases.  Consolidation is therefore display-only and runs
        // after canonical selection but before running balances are projected.
        $events = $this->consolidateSupplierPaymentDocuments($events);
        $customerRunning = 0.0;
        $supplierRunning = 0.0;
        $chronological = $events->map(function (array $event) use (
            &$customerRunning,
            &$supplierRunning,
            $orientation,
        ): array {
            $customerDisplayDelta = (float) $event['customer_delta'] - (float) $event['supplier_delta'];
            $supplierDisplayDelta = -$customerDisplayDelta;
            if ((bool) ($event['affects_balance'] ?? false)) {
                $customerRunning += $customerDisplayDelta;
                $supplierRunning += $supplierDisplayDelta;
            }
            $displayDelta = $orientation === 'customer' ? $customerDisplayDelta : $supplierDisplayDelta;
            $runningBalance = $orientation === 'customer' ? $customerRunning : $supplierRunning;
            $metadata = (array) ($event['metadata'] ?? []);

            return array_merge($metadata, $event, [
                'id' => (string) $event['event_identity'],
                'code' => (string) ($event['source_code'] ?? ''),
                'type' => (string) ($event['display_type'] ?? $event['event_kind']),
                'type_label' => (string) ($event['display_type'] ?? $event['event_kind']),
                'time' => (string) $event['business_time'],
                'display_time' => (string) $event['business_time'],
                'customer_display_delta' => $customerDisplayDelta,
                'supplier_display_delta' => $supplierDisplayDelta,
                'display_delta' => $displayDelta,
                'customer_display_effect' => $customerDisplayDelta,
                'supplier_display_effect' => $supplierDisplayDelta,
                'display_effect' => $displayDelta,
                'financial_effect' => $displayDelta,
                'amount' => $displayDelta,
                'customer_running_balance' => $customerRunning,
                'supplier_running_balance' => $supplierRunning,
                'customer_display_running_balance' => $customerRunning,
                'supplier_display_running_balance' => $supplierRunning,
                'running_balance' => $runningBalance,
                'debt_remain' => $runningBalance,
                'balance' => $runningBalance,
                'source' => 'canonical_partner_debt_events',
                'source_ledger' => match ((string) ($event['domain'] ?? '')) {
                    'customer' => 'customer_receivable',
                    'supplier' => 'supplier_payable',
                    default => 'partner_both_sides',
                },
                'affects_document_balance' => (bool) ($event['affects_balance'] ?? false),
                'affects_debt_balance' => (bool) ($event['affects_balance'] ?? false),
                'affects_canonical_balance' => (bool) ($event['affects_balance'] ?? false),
                'is_reference_only' => (bool) ($event['reference_only'] ?? false),
                'detail_available' => (string) ($event['detail_type'] ?? 'none') !== 'none',
                'detail_modal_type' => (string) ($event['detail_type'] ?? 'none'),
                'detail_reference_id' => $event['detail_id'] ?? null,
                'detail_reference_code' => $event['detail_code'] ?? null,
                'reference_type' => $metadata['reference_type'] ?? $event['source_type'],
                'reference_id' => $metadata['reference_id'] ?? $event['source_id'],
                'reference_code' => $metadata['reference_code'] ?? $event['source_code'],
                'reversal_of' => $event['reversal_of_event_identity'] ?? null,
                'mirror_of' => $event['mirror_of_event_identity'] ?? null,
                'is_virtual_fallback' => (bool) ($event['is_fallback'] ?? false),
            ]);
        })->values();

        $rawFinal = $orientation === 'customer' ? $customerRunning : $supplierRunning;

        return [$chronological->reverse()->values(), $rawFinal];
    }

    /**
     * Collapse allocation rows belonging to the same authoritative CashFlow
     * document for display only.  The returned row keeps the first canonical
     * identity and carries every hidden identity in metadata so exports and
     * audits can still inspect the complete evidence stream.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private function consolidateSupplierPaymentDocuments(Collection $events): Collection
    {
        $rows = [];
        $groupIndexes = [];

        foreach ($events->values() as $event) {
            $groupKey = $this->supplierPaymentDisplayGroupKey($event);
            if ($groupKey === null) {
                $rows[] = $event;

                continue;
            }

            if (! array_key_exists($groupKey, $groupIndexes)) {
                $groupIndexes[$groupKey] = count($rows);
                $rows[] = $this->initializeSupplierPaymentDisplayRow($event, $groupKey);

                continue;
            }

            $index = $groupIndexes[$groupKey];
            $representative = $rows[$index];
            $rows[$index] = $this->mergeSupplierPaymentDisplayRow($representative, $event);
        }

        return collect($rows)->values();
    }

    /**
     * Preserve source-level technical mirror evidence for audit consumers
     * without putting those rows back into the canonical balance.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function excludedLedgerEntries(Collection $events): array
    {
        return $events
            ->filter(fn (array $event): bool => (bool) (($event['metadata']['excluded_from_document_balance'] ?? false)))
            ->map(function (array $event): array {
                $metadata = (array) ($event['metadata'] ?? []);

                return [
                    'code' => $metadata['code'] ?? $metadata['source_code'] ?? $metadata['reference_code'] ?? null,
                    'amount' => (float) ($metadata['amount'] ?? $metadata['document_amount'] ?? 0.0),
                    'reason' => $metadata['excluded_reason'] ?? 'technical_ledger_excluded_from_document_timeline',
                    // The event's generic source (usually `ledger`) is not the
                    // persisted document table used by the audit contract. Keep
                    // the concrete source table whenever it is available.
                    'source' => $event['source_table'] ?? $metadata['source_table'] ?? $metadata['source'] ?? null,
                ];
            })
            ->filter(fn (array $entry): bool => (string) ($entry['code'] ?? '') !== '')
            ->unique(fn (array $entry): string => implode('|', [
                (string) ($entry['source'] ?? ''),
                (string) ($entry['code'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function initializeSupplierPaymentDisplayRow(array $event, string $groupKey): array
    {
        $metadata = (array) ($event['metadata'] ?? []);
        $cashFlowId = $event['detail_id'] ?? ($metadata['detail_reference_id'] ?? null);
        $cashFlowCode = $event['detail_code'] ?? ($metadata['detail_reference_code'] ?? null);
        $allocationIsActual = (bool) ($metadata['allocation_is_actual'] ?? false);
        $allocationTotal = $allocationIsActual
            ? (float) ($metadata['original_allocated_amount'] ?? $metadata['allocated_amount'] ?? 0.0)
            : 0.0;
        $paymentAmount = abs((float) ($event['supplier_delta'] ?? 0.0));
        $unallocatedAmount = $allocationIsActual
            ? max(0.0, $paymentAmount - $allocationTotal)
            : 0.0;
        $allocationMismatch = (bool) ($metadata['payment_allocation_mismatch'] ?? false)
            || ($allocationIsActual && $unallocatedAmount > 0.01);
        $needsManualReview = (bool) ($metadata['needs_manual_review'] ?? false) || $allocationMismatch;
        $canonicalIdentities = [(string) ($event['event_identity'] ?? '')];
        $displayMetadata = array_merge($metadata, [
            'allocation_count' => $allocationIsActual ? 1 : 0,
            'allocation_total' => $allocationTotal,
            'purchase_ids' => $allocationIsActual && ($metadata['reference_id'] ?? null) !== null
                ? [(int) $metadata['reference_id']]
                : [],
            'purchase_codes' => $allocationIsActual && ($metadata['reference_code'] ?? null) !== null
                ? [(string) $metadata['reference_code']]
                : [],
            'allocation_purchase_ids' => $allocationIsActual && ($metadata['reference_id'] ?? null) !== null
                ? [(int) $metadata['reference_id']]
                : [],
            'allocation_purchase_codes' => $allocationIsActual && ($metadata['reference_code'] ?? null) !== null
                ? [(string) $metadata['reference_code']]
                : [],
            'payment_amount' => $paymentAmount,
            'payment_cash_flow_id' => $cashFlowId,
            'payment_cash_flow_code' => $cashFlowCode,
            'payment_allocation_mismatch' => $allocationMismatch,
            'needs_manual_review' => $needsManualReview,
            'unallocated_amount' => $unallocatedAmount,
            'canonical_event_identities' => $canonicalIdentities,
            'canonical_event_count' => 1,
            'display_group_key' => $groupKey,
            'display_projection' => 'supplier_payment_document',
        ]);

        return array_merge($event, [
            'allocation_count' => $displayMetadata['allocation_count'],
            'allocation_total' => $allocationTotal,
            'purchase_ids' => $displayMetadata['purchase_ids'],
            'purchase_codes' => $displayMetadata['purchase_codes'],
            'allocation_purchase_ids' => $displayMetadata['purchase_ids'],
            'allocation_purchase_codes' => $displayMetadata['purchase_codes'],
            'payment_amount' => $paymentAmount,
            'payment_cash_flow_id' => $cashFlowId,
            'payment_cash_flow_code' => $cashFlowCode,
            'payment_allocation_mismatch' => $allocationMismatch,
            'needs_manual_review' => $needsManualReview,
            'unallocated_amount' => $unallocatedAmount,
            'canonical_event_identities' => $canonicalIdentities,
            'canonical_event_count' => 1,
            'display_group_key' => $groupKey,
            'display_projection' => 'supplier_payment_document',
            'metadata' => $displayMetadata,
        ]);
    }

    /**
     * Group only by the real CashFlow identity.  Codes, timestamps and
     * amounts are intentionally not part of the key because they can collide.
     */
    private function supplierPaymentDisplayGroupKey(array $event): ?string
    {
        if ((string) ($event['domain'] ?? '') !== 'supplier'
            || (string) ($event['event_kind'] ?? '') !== 'supplier_payment'
            || (string) ($event['source_type'] ?? $event['source_table'] ?? '') !== 'cash_flows'
            || ! (bool) ($event['is_real_voucher'] ?? false)
            || (bool) ($event['is_fallback'] ?? false)
        ) {
            return null;
        }

        $cashFlowId = $event['detail_id']
            ?? ($event['metadata']['detail_reference_id'] ?? null)
            ?? ($event['metadata']['reference_id'] ?? null);
        if ($cashFlowId === null || (string) $cashFlowId === '') {
            return null;
        }

        return implode('|', ['cash_flows', (string) $cashFlowId, 'supplier_payment']);
    }

    /** @return array<string, mixed> */
    private function mergeSupplierPaymentDisplayRow(array $representative, array $event): array
    {
        $representativeMetadata = (array) ($representative['metadata'] ?? []);
        $eventMetadata = (array) ($event['metadata'] ?? []);
        $canonicalIdentities = array_values(array_unique(array_merge(
            (array) ($representative['canonical_event_identities'] ?? [$representative['event_identity'] ?? '']),
            (array) ($event['canonical_event_identities'] ?? [$event['event_identity'] ?? '']),
        )));

        $supplierDelta = (float) ($representative['supplier_delta'] ?? 0.0)
            + (float) ($event['supplier_delta'] ?? 0.0);
        $customerDelta = (float) ($representative['customer_delta'] ?? 0.0)
            + (float) ($event['customer_delta'] ?? 0.0);

        $representativeAllocationCount = array_key_exists('allocation_count', $representative)
            ? (int) $representative['allocation_count']
            : ((bool) ($representativeMetadata['allocation_is_actual'] ?? false) ? 1 : 0);
        $eventAllocationCount = array_key_exists('allocation_count', $event)
            ? (int) $event['allocation_count']
            : ((bool) ($eventMetadata['allocation_is_actual'] ?? false) ? 1 : 0);
        $representativeAllocationTotal = array_key_exists('allocation_total', $representative)
            ? (float) $representative['allocation_total']
            : (float) ($representativeMetadata['original_allocated_amount']
                ?? $representativeMetadata['allocated_amount']
                ?? 0.0);
        $eventAllocationTotal = array_key_exists('allocation_total', $event)
            ? (float) $event['allocation_total']
            : (float) ($eventMetadata['original_allocated_amount']
                ?? $eventMetadata['allocated_amount']
                ?? 0.0);
        $allocationCount = $representativeAllocationCount + $eventAllocationCount;
        $allocationTotal = $representativeAllocationTotal + $eventAllocationTotal;
        $purchaseIds = array_values(array_unique(array_filter(array_merge(
            (array) ($representative['purchase_ids'] ?? []),
            (array) ($event['purchase_ids'] ?? []),
            [$representativeMetadata['reference_id'] ?? null, $eventMetadata['reference_id'] ?? null],
        ), fn ($id): bool => $id !== null && (string) $id !== '')));
        $purchaseCodes = array_values(array_unique(array_filter(array_merge(
            (array) ($representative['purchase_codes'] ?? []),
            (array) ($event['purchase_codes'] ?? []),
            [$representativeMetadata['reference_code'] ?? null, $eventMetadata['reference_code'] ?? null],
        ), fn ($code): bool => $code !== null && (string) $code !== '')));
        // The voucher amount is the absolute canonical effect of the whole
        // CashFlow group (allocations plus any persisted unallocated residue).
        $paymentAmount = abs($supplierDelta);
        $cashFlowId = $representative['detail_id']
            ?? ($representativeMetadata['detail_reference_id'] ?? null);
        $cashFlowCode = $representative['detail_code']
            ?? ($representativeMetadata['detail_reference_code'] ?? null);
        $hasActualAllocations = $allocationCount > 0;
        $unallocatedAmount = $hasActualAllocations
            ? max(0.0, $paymentAmount - $allocationTotal)
            : 0.0;
        $allocationMismatch = (bool) ($representative['payment_allocation_mismatch'] ?? false)
            || (bool) ($event['payment_allocation_mismatch'] ?? false)
            || ($hasActualAllocations && $unallocatedAmount > 0.01);
        $needsManualReview = (bool) ($representative['needs_manual_review'] ?? false)
            || (bool) ($event['needs_manual_review'] ?? false)
            || $allocationMismatch;

        $metadata = array_merge($representativeMetadata, [
            'allocation_count' => $allocationCount,
            'allocation_total' => $allocationTotal,
            'purchase_ids' => $purchaseIds,
            'purchase_codes' => $purchaseCodes,
            'allocation_purchase_ids' => $purchaseIds,
            'allocation_purchase_codes' => $purchaseCodes,
            'payment_amount' => $paymentAmount,
            'payment_cash_flow_id' => $cashFlowId,
            'payment_cash_flow_code' => $cashFlowCode,
            'payment_allocation_mismatch' => $allocationMismatch,
            'needs_manual_review' => $needsManualReview,
            'unallocated_amount' => $unallocatedAmount,
            'canonical_event_identities' => $canonicalIdentities,
            'canonical_event_count' => count($canonicalIdentities),
            'display_group_key' => implode('|', [
                'cash_flows',
                (string) ($representative['detail_id'] ?? $representativeMetadata['detail_reference_id'] ?? ''),
                'supplier_payment',
            ]),
            'display_projection' => 'supplier_payment_document',
        ]);

        return array_merge($representative, [
            'customer_delta' => $customerDelta,
            'supplier_delta' => $supplierDelta,
            'allocation_count' => $allocationCount,
            'allocation_total' => $allocationTotal,
            'purchase_ids' => $purchaseIds,
            'purchase_codes' => $purchaseCodes,
            'allocation_purchase_ids' => $purchaseIds,
            'allocation_purchase_codes' => $purchaseCodes,
            'payment_amount' => $paymentAmount,
            'payment_cash_flow_id' => $cashFlowId,
            'payment_cash_flow_code' => $cashFlowCode,
            'payment_allocation_mismatch' => $allocationMismatch,
            'needs_manual_review' => $needsManualReview,
            'unallocated_amount' => $unallocatedAmount,
            'canonical_event_identities' => $canonicalIdentities,
            'canonical_event_count' => count($canonicalIdentities),
            'display_group_key' => $metadata['display_group_key'],
            'display_projection' => 'supplier_payment_document',
            'metadata' => $metadata,
        ]);
    }
}
