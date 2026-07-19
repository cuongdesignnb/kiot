<?php

namespace App\Services\Debt;

use App\Models\Customer;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Collection;

/**
 * Five-layer audit: domain, list scopes, both UI orientations and dual-role
 * event-by-event symmetry.
 */
class PartnerDebtTimelineAuditService
{
    private const TOLERANCE = 1.0;

    public function __construct(
        private readonly CanonicalPartnerDebtEventService $events,
        private readonly CanonicalPartnerDebtService $balances,
        private readonly PartnerDebtTimelineOrientationService $orientation,
    ) {}

    /** @return array<string, mixed> */
    public function audit(Customer $partner): array
    {
        $role = PartnerDebtRoleResolver::integrity($partner);
        $canonicalEvents = $this->events->build($partner);
        $balance = $this->balances->calculate($partner);
        $customer = $this->orientation->customer($partner, ['audit' => true]);
        $supplier = $this->orientation->supplier($partner, ['audit' => true]);
        $customerEntries = collect($customer['entries'] ?? [])->map(fn ($entry): array => (array) $entry);
        $supplierEntries = collect($supplier['entries'] ?? [])->map(fn ($entry): array => (array) $entry);

        $customerListActual = Customer::query()->whereKey($partner->id)->where('is_customer', true)->exists();
        $supplierListActual = Customer::query()->whereKey($partner->id)->where('is_supplier', true)->exists();
        $customerListMismatch = $customerListActual !== (bool) $role['persisted_customer'];
        $supplierListMismatch = $supplierListActual !== (bool) $role['persisted_supplier'];

        $customerDomainDifference = (float) $balance['customer_receivable'] - (float) $partner->debt_amount;
        $supplierDomainDifference = (float) $balance['supplier_payable'] - (float) $partner->supplier_debt_amount;
        $domainPass = ! $this->different($customerDomainDifference)
            && ! $this->different($supplierDomainDifference);
        $customerViewPass = ! (bool) $role['persisted_customer'] || ! (bool) $customer['has_mismatch'];
        $supplierViewPass = ! (bool) $role['persisted_supplier'] || ! (bool) $supplier['has_mismatch'];

        $cross = $this->crossView(
            (bool) $role['persisted_customer'] && (bool) $role['persisted_supplier'],
            $customerEntries,
            $supplierEntries,
        );
        $safety = $this->safety($canonicalEvents);
        $flags = $this->flags(
            $role,
            $domainPass,
            $customerListMismatch,
            $supplierListMismatch,
            $customerViewPass,
            $supplierViewPass,
            $cross,
            $safety,
        );

        return [
            'persisted_role' => $role['persisted_role'],
            'effective_role' => $role['effective_role'],
            'evidence_role' => $role['evidence_role'],
            'role_integrity_status' => $role['role_integrity_status'],
            'owner_confirmed_role' => $role['owner_confirmed_role'],
            'customer_list_expected' => (bool) $role['persisted_customer'],
            'customer_list_actual' => $customerListActual,
            'customer_list_scope_mismatch' => $customerListMismatch,
            'supplier_list_expected' => (bool) $role['persisted_supplier'],
            'supplier_list_actual' => $supplierListActual,
            'supplier_list_scope_mismatch' => $supplierListMismatch,
            'canonical_customer_receivable' => (float) $balance['customer_receivable'],
            'canonical_supplier_payable' => (float) $balance['supplier_payable'],
            'customer_domain_difference' => $customerDomainDifference,
            'supplier_domain_difference' => $supplierDomainDifference,
            'domain_parity_pass' => $domainPass,
            'customer_view_target' => (float) $customer['target_balance'],
            'customer_view_final' => (float) $customer['raw_final_balance'],
            'customer_view_difference' => (float) $customer['difference'],
            'customer_view_parity_pass' => $customerViewPass,
            'customer_view_warning' => (bool) $customer['has_mismatch'],
            'customer_view_entry_count' => (int) $customer['entry_count'],
            'customer_source_identity_hash' => (string) $customer['source_identity_hash'],
            'supplier_view_target' => (float) $supplier['target_balance'],
            'supplier_view_final' => (float) $supplier['raw_final_balance'],
            'supplier_view_difference' => (float) $supplier['difference'],
            'supplier_view_parity_pass' => $supplierViewPass,
            'supplier_view_warning' => (bool) $supplier['has_mismatch'],
            'supplier_view_entry_count' => (int) $supplier['entry_count'],
            'supplier_source_identity_hash' => (string) $supplier['source_identity_hash'],
            ...$cross,
            ...$safety,
            'timeline_classification_flags' => $flags,
            'timeline_primary_classification' => $flags[0] ?? 'OK',
            'all_applicable_layers_pass' => $flags === [],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $customer
     * @param  Collection<int, array<string, mixed>>  $supplier
     * @return array<string, mixed>
     */
    private function crossView(bool $applicable, Collection $customer, Collection $supplier): array
    {
        if (! $applicable) {
            return [
                'cross_view_applicable' => false,
                'cross_view_parity_pass' => true,
                'cross_view_event_missing_count' => 0,
                'cross_view_event_extra_count' => 0,
                'cross_view_sign_mismatch_count' => 0,
                'cross_view_order_mismatch_count' => 0,
                'cross_view_running_mismatch_count' => 0,
                'cross_view_first_divergence' => null,
            ];
        }

        $customerIdentities = $customer->pluck('event_identity')->map('strval')->values();
        $supplierIdentities = $supplier->pluck('event_identity')->map('strval')->values();
        $missing = $supplierIdentities->diff($customerIdentities)->count();
        $extra = $customerIdentities->diff($supplierIdentities)->count();
        $orderMismatch = $customerIdentities->all() === $supplierIdentities->all() ? 0 : 1;
        $supplierByIdentity = $supplier->keyBy('event_identity');
        $signMismatch = 0;
        $runningMismatch = 0;
        $firstDivergence = null;

        foreach ($customer as $index => $entry) {
            $identity = (string) ($entry['event_identity'] ?? '');
            $opposite = $supplierByIdentity->get($identity);
            if (! is_array($opposite)) {
                $firstDivergence ??= $identity !== '' ? $identity : "index:{$index}";

                continue;
            }
            if ($this->different((float) $entry['display_delta'] + (float) $opposite['display_delta'])) {
                $signMismatch++;
                $firstDivergence ??= $identity;
            }
            if ($this->different((float) $entry['running_balance'] + (float) $opposite['running_balance'])) {
                $runningMismatch++;
                $firstDivergence ??= $identity;
            }
        }

        if ($orderMismatch && $firstDivergence === null) {
            $limit = max($customerIdentities->count(), $supplierIdentities->count());
            for ($index = 0; $index < $limit; $index++) {
                if ($customerIdentities->get($index) !== $supplierIdentities->get($index)) {
                    $firstDivergence = (string) ($customerIdentities->get($index) ?? $supplierIdentities->get($index) ?? "index:{$index}");
                    break;
                }
            }
        }

        return [
            'cross_view_applicable' => true,
            'cross_view_parity_pass' => $missing === 0
                && $extra === 0
                && $orderMismatch === 0
                && $signMismatch === 0
                && $runningMismatch === 0,
            'cross_view_event_missing_count' => $missing,
            'cross_view_event_extra_count' => $extra,
            'cross_view_sign_mismatch_count' => $signMismatch,
            'cross_view_order_mismatch_count' => $orderMismatch,
            'cross_view_running_mismatch_count' => $runningMismatch,
            'cross_view_first_divergence' => $firstDivergence,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, int>
     */
    private function safety(Collection $events): array
    {
        $storedBalanceEvents = $events->filter(fn (array $event): bool => in_array(
            (string) ($event['source_type'] ?? ''),
            ['customers', 'stored_projection'],
            true,
        ))->count();
        $virtualOpening = $events->filter(fn (array $event): bool => str_contains(
            (string) ($event['event_kind'] ?? ''),
            'virtual_opening',
        ))->count();
        $mirrorCounted = $events->filter(fn (array $event): bool => (bool) ($event['reference_only'] ?? false)
            && (bool) ($event['affects_balance'] ?? false))->count();
        $realAndFallbackDoubleCount = $events
            ->where('affects_balance', true)
            ->groupBy(function (array $event): string {
                $metadata = (array) ($event['metadata'] ?? []);
                $kind = preg_replace('/_fallback$/', '', (string) ($event['event_kind'] ?? ''));
                $documentKey = (string) ($metadata['parent_document_code']
                    ?? $metadata['document_group_parent_code']
                    ?? $metadata['reference_code']
                    ?? '');
                if ($documentKey === '') {
                    $documentKey = (string) ($event['source_type'] ?? '').':'.(string) ($event['source_id'] ?? '');
                }

                return implode('|', [(string) ($event['domain'] ?? ''), $kind, $documentKey]);
            })
            ->filter(function (Collection $group): bool {
                $fallbacks = $group->where('is_fallback', true);
                if ($fallbacks->isEmpty() || ! $group->contains(fn (array $event): bool => ! (bool) ($event['is_fallback'] ?? false))) {
                    return false;
                }

                return $fallbacks->contains(function (array $event): bool {
                    $metadata = (array) ($event['metadata'] ?? []);

                    return ! (bool) ($metadata['fallback_for_unallocated_amount'] ?? false)
                        && ! array_key_exists('fallback_uncovered_paid_amount', $metadata);
                });
            })
            ->count();
        $cancellationAsymmetry = 0;
        $byIdentity = $events->keyBy('event_identity');
        $reversedIdentities = $events->pluck('reversal_of_event_identity')->filter()->flip();
        foreach ($events->whereNotNull('reversal_of_event_identity') as $reversal) {
            $original = $byIdentity->get((string) $reversal['reversal_of_event_identity']);
            if (! is_array($original)
                || $this->different((float) $original['customer_delta'] + (float) $reversal['customer_delta'])
                || $this->different((float) $original['supplier_delta'] + (float) $reversal['supplier_delta'])) {
                $cancellationAsymmetry++;
            }
        }
        foreach ($events as $original) {
            $kind = (string) ($original['event_kind'] ?? '');
            if (BusinessStatus::isCancelled($original['source_status'] ?? null)
                && ! str_contains($kind, 'cancel')
                && ! str_contains($kind, 'reversal')
                && (bool) ($original['affects_balance'] ?? false)
                && ! $reversedIdentities->has((string) $original['event_identity'])) {
                $cancellationAsymmetry++;
            }
        }

        return [
            'virtual_opening_event_count' => $virtualOpening,
            'display_alignment_event_count' => 0,
            'stored_balance_event_count' => $storedBalanceEvents,
            'mirror_counted_as_financial_event_count' => $mirrorCounted,
            'real_and_fallback_double_count' => $realAndFallbackDoubleCount,
            'cancel_reversal_asymmetry_count' => $cancellationAsymmetry,
        ];
    }

    /** @return array<int, string> */
    private function flags(
        array $role,
        bool $domainPass,
        bool $customerListMismatch,
        bool $supplierListMismatch,
        bool $customerViewPass,
        bool $supplierViewPass,
        array $cross,
        array $safety,
    ): array {
        $flags = [];
        if (($role['role_integrity_status'] ?? 'OK') !== 'OK') {
            $flags[] = (string) $role['role_integrity_status'];
        }
        if ($customerListMismatch) {
            $flags[] = 'CUSTOMER_LIST_SCOPE_MISMATCH';
        }
        if ($supplierListMismatch) {
            $flags[] = 'SUPPLIER_LIST_SCOPE_MISMATCH';
        }
        if (! $domainPass) {
            $flags[] = 'DOMAIN_PARITY_MISMATCH';
        }
        if (! $customerViewPass) {
            $flags[] = 'CUSTOMER_VIEW_TIMELINE_MISMATCH';
        }
        if (! $supplierViewPass) {
            $flags[] = 'SUPPLIER_VIEW_TIMELINE_MISMATCH';
        }
        foreach ([
            'cross_view_event_missing_count' => 'CROSS_VIEW_EVENT_MISSING',
            'cross_view_event_extra_count' => 'CROSS_VIEW_EVENT_EXTRA',
            'cross_view_order_mismatch_count' => 'CROSS_VIEW_EVENT_ORDER_MISMATCH',
            'cross_view_sign_mismatch_count' => 'CROSS_VIEW_SIGN_MISMATCH',
            'cross_view_running_mismatch_count' => 'CROSS_VIEW_RUNNING_BALANCE_MISMATCH',
            'mirror_counted_as_financial_event_count' => 'MIRROR_COUNTED_AS_FINANCIAL_EVENT',
            'real_and_fallback_double_count' => 'REAL_AND_FALLBACK_DOUBLE_COUNT',
            'cancel_reversal_asymmetry_count' => 'CANCEL_REVERSAL_ASYMMETRY',
            'stored_balance_event_count' => 'STORED_BALANCE_USED_AS_EVENT',
        ] as $key => $classification) {
            if ((int) (($cross + $safety)[$key] ?? 0) > 0) {
                $flags[] = $classification;
            }
        }
        if ((int) ($safety['virtual_opening_event_count'] ?? 0) > 0) {
            $flags[] = 'VIRTUAL_OPENING_REQUIRED';
        }

        return array_values(array_unique($flags));
    }

    private function different(float $difference): bool
    {
        return abs($difference) > self::TOLERANCE;
    }
}
