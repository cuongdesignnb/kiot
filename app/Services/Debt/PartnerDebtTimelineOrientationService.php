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
        $canonical = $this->events->build($partner);
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
            'excluded_ledger_entries' => [],
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
}
