<?php

namespace App\Services\Debt;

use App\Models\Customer;
use App\Services\Debt\Source\CustomerDebtDomainEventSource;
use App\Services\Debt\Source\SupplierDebtDomainEventSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The single persisted-evidence event stream for a partner's two debt sides.
 *
 * Stored projections are intentionally absent from this reducer. Customer and
 * supplier screens consume this same collection through opposite orientations.
 */
class CanonicalPartnerDebtEventService
{
    public const CONTRACT_VERSION = 'kiotviet-partner-debt-events-v1';

    public function __construct(
        private readonly CustomerDebtDomainEventSource $customerSource,
        private readonly SupplierDebtDomainEventSource $supplierSource,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function build(Customer $partner): Collection
    {
        if (! $partner->exists) {
            return collect();
        }

        $entries = $this->customerSource->events($partner)
            ->concat($this->supplierSource->events($partner))
            ->map(fn (array $entry): array => $this->normalize($partner, $entry));

        return $this->canonicalize($entries);
    }

    /**
     * Exposed for deterministic fixtures and source-contract tests. Runtime
     * callers should use build(), which obtains persisted evidence itself.
     *
     * @param  iterable<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    public function canonicalize(iterable $entries): Collection
    {
        $events = collect($entries)->values();
        $offsetEvents = $events->filter(fn (array $event): bool => $this->isDebtOffset($event));
        $ordinaryEvents = $events->reject(fn (array $event): bool => $this->isDebtOffset($event));

        $combinedOffsets = $offsetEvents
            ->groupBy(fn (array $event): string => implode('|', [
                (string) ($event['source_type'] ?? ''),
                (string) ($event['source_id'] ?? ''),
                (string) ($event['event_kind'] ?? ''),
            ]))
            ->map(fn (Collection $group): array => $this->combineDebtOffset($group));

        $deduplicated = $ordinaryEvents
            ->concat($combinedOffsets)
            ->sortBy([
                [fn (array $event): int => ($event['reference_only'] ?? false) ? 1 : 0, 'asc'],
                [fn (array $event): int => ($event['is_real_voucher'] ?? false) ? 0 : 1, 'asc'],
            ])
            ->unique(fn (array $event): string => (string) $event['event_identity'])
            ->values();

        $sorted = $deduplicated
            ->sort(function (array $left, array $right): int {
                foreach (['business_time', 'event_order', 'event_identity'] as $key) {
                    $comparison = $key === 'event_order'
                        ? ((int) ($left[$key] ?? 0) <=> (int) ($right[$key] ?? 0))
                        : strcmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->values();

        return $this->resolveReversalLinks($sorted);
    }

    /** @param Collection<int, array<string, mixed>> $events */
    public function identityHash(Collection $events): string
    {
        return hash('sha256', $events
            ->pluck('event_identity')
            ->map(fn ($identity): string => (string) $identity)
            ->implode("\n"));
    }

    /** @return array<string, mixed> */
    private function normalize(Customer $partner, array $entry): array
    {
        $domain = (string) ($entry['domain'] ?? '');
        $eventKind = (string) ($entry['event_kind'] ?? 'unknown');
        $sourceType = (string) ($entry['source_type'] ?? $entry['source_table'] ?? 'unknown');
        $sourceId = $this->sourceId($entry, $sourceType);
        $effectSide = (string) ($entry['effect_side'] ?? ($domain === 'supplier' ? 'payable' : 'receivable'));
        $effect = $this->sourceEffect($entry, $domain);
        $affectsBalance = (bool) ($entry['affects_canonical_balance'] ?? true)
            && (bool) ($entry['affects_document_balance'] ?? true)
            && (bool) ($entry['affects_debt_balance'] ?? true);
        $referenceOnly = (bool) ($entry['reference_only'] ?? $entry['is_reference_only'] ?? false)
            || ! $affectsBalance;

        $customerDelta = $domain === 'customer' ? $effect : 0.0;
        $supplierDelta = $domain === 'supplier' ? $effect : 0.0;
        if (array_key_exists('customer_delta', $entry)) {
            $customerDelta = (float) $entry['customer_delta'];
        }
        if (array_key_exists('supplier_delta', $entry)) {
            $supplierDelta = (float) $entry['supplier_delta'];
        }

        $eventIdentity = implode('|', [$domain, $sourceType, $sourceId, $eventKind, $effectSide]);
        [$persistedCustomer, $persistedSupplier] = PartnerDebtRoleResolver::persistedSides($partner);

        return [
            'event_identity' => $eventIdentity,
            'economic_partner_id' => (int) $partner->id,
            'customer_row_id' => $persistedCustomer ? (int) $partner->id : null,
            'supplier_row_id' => $persistedSupplier ? (int) $partner->id : null,
            'domain' => $domain,
            'source_type' => $sourceType,
            'source_table' => $sourceType,
            'source_id' => $sourceId,
            'source_code' => (string) ($entry['source_code'] ?? $entry['code'] ?? $entry['reference_code'] ?? ''),
            'event_kind' => $eventKind,
            'business_time' => $this->time($entry['business_time'] ?? $entry['display_time'] ?? $entry['time'] ?? $entry['created_at'] ?? null),
            'created_at' => $this->time($entry['created_at'] ?? $entry['business_time'] ?? $entry['time'] ?? null),
            'event_order' => $this->eventOrder($eventKind),
            'customer_delta' => $customerDelta,
            'supplier_delta' => $supplierDelta,
            'affects_balance' => $affectsBalance && ! $referenceOnly,
            'reference_only' => $referenceOnly,
            'mirror_of_event_identity' => $entry['mirror_of_event_identity'] ?? $entry['mirror_of'] ?? null,
            'reversal_of_event_identity' => $entry['reversal_of_event_identity'] ?? $entry['reversal_of'] ?? null,
            'source_status' => $entry['source_status'] ?? $entry['status'] ?? null,
            'detail_type' => (string) ($entry['detail_type'] ?? $entry['detail_modal_type'] ?? 'none'),
            'detail_id' => $entry['detail_id'] ?? $entry['detail_reference_id'] ?? $entry['reference_id'] ?? null,
            'detail_code' => $entry['detail_code'] ?? $entry['detail_reference_code'] ?? $entry['reference_code'] ?? $entry['code'] ?? null,
            'display_type' => (string) ($entry['display_type'] ?? $entry['type_label'] ?? $eventKind),
            'badge_label' => $entry['badge_label'] ?? null,
            'badge_title' => $entry['badge_title'] ?? null,
            'is_real_voucher' => (bool) ($entry['is_real_voucher'] ?? false),
            'is_fallback' => (bool) ($entry['is_virtual_fallback'] ?? false),
            'metadata' => $entry,
        ];
    }

    private function sourceId(array $entry, string $sourceType): string
    {
        $legacyId = (string) ($entry['id'] ?? '');
        if ($sourceType === 'cash_flows' && str_contains($legacyId, 'allocation-')) {
            return $legacyId;
        }

        return (string) ($entry['source_id']
            ?? $entry['detail_reference_id']
            ?? $entry['reference_id']
            ?? $legacyId);
    }

    private function sourceEffect(array $entry, string $domain): float
    {
        $keys = $domain === 'supplier'
            ? ['supplier_balance_effect', 'supplier_display_balance_effect', 'supplier_display_effect', 'display_effect', 'amount']
            : ['customer_effect', 'customer_display_effect', 'display_effect', 'amount'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        return 0.0;
    }

    private function isDebtOffset(array $event): bool
    {
        return ($event['source_type'] ?? null) === 'debt_offsets'
            && in_array((string) ($event['event_kind'] ?? ''), ['debt_offset', 'debt_offset_cancel', 'debt_offset_reversal'], true);
    }

    /** @param Collection<int, array<string, mixed>> $group */
    private function combineDebtOffset(Collection $group): array
    {
        $first = $group->first();
        $kind = (string) $first['event_kind'];
        $amount = (float) $group->max(function (array $event): float {
            $metadata = (array) ($event['metadata'] ?? []);

            return max(
                abs((float) ($metadata['document_amount'] ?? 0)),
                abs((float) ($event['customer_delta'] ?? 0)),
                abs((float) ($event['supplier_delta'] ?? 0)),
            );
        });
        $delta = in_array($kind, ['debt_offset_cancel', 'debt_offset_reversal'], true) ? $amount : -$amount;
        $sourceType = (string) $first['source_type'];
        $sourceId = (string) $first['source_id'];
        $identity = implode('|', ['partner', $sourceType, $sourceId, $kind, 'both']);

        return array_merge($first, [
            'event_identity' => $identity,
            'domain' => 'partner',
            'customer_delta' => $delta,
            'supplier_delta' => $delta,
            'affects_balance' => true,
            'reference_only' => false,
            'mirror_of_event_identity' => null,
            'reversal_of_event_identity' => $kind === 'debt_offset'
                ? null
                : ((string) ($first['reversal_of_event_identity'] ?? '') !== ''
                    ? $first['reversal_of_event_identity']
                    : implode('|', ['partner', $sourceType, $sourceId, 'debt_offset', 'both'])),
            'metadata' => [
                'combined_source_events' => $group->pluck('event_identity')->values()->all(),
                'business_rule' => 'reduce_receivable_and_payable_once',
            ],
        ]);
    }

    private function eventOrder(string $eventKind): int
    {
        if (str_contains($eventKind, 'cancel') || str_contains($eventKind, 'reversal')) {
            return 60;
        }
        if (str_contains($eventKind, 'opening') || str_contains($eventKind, 'import') || str_contains($eventKind, 'merge')) {
            return 10;
        }
        if (in_array($eventKind, ['customer_sale', 'invoice', 'purchase'], true)) {
            return 20;
        }
        if (str_contains($eventKind, 'return')) {
            return 30;
        }
        if (str_contains($eventKind, 'payment') || str_contains($eventKind, 'receipt') || str_contains($eventKind, 'refund')) {
            return 40;
        }

        return 50;
    }

    /**
     * Legacy cancellation evidence sometimes points to the original family
     * identity while the persisted original is a real cash-flow or fallback
     * allocation identity. Resolve that link by exact opposite deltas plus a
     * shared persisted document key; never infer from amount alone.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveReversalLinks(Collection $events): Collection
    {
        $identities = $events->pluck('event_identity')->flip();

        return $events->map(function (array $reversal) use ($events, $identities): array {
            $link = (string) ($reversal['reversal_of_event_identity'] ?? '');
            if ($link === '' || $identities->has($link)) {
                return $reversal;
            }

            $reversalKeys = $this->correlationKeys($reversal);
            $candidate = $events
                ->filter(function (array $original) use ($reversal, $reversalKeys): bool {
                    if ((string) $original['event_identity'] === (string) $reversal['event_identity']
                        || (string) $original['business_time'] > (string) $reversal['business_time']
                        || str_contains((string) $original['event_kind'], 'cancel')
                        || str_contains((string) $original['event_kind'], 'reversal')) {
                        return false;
                    }
                    if (abs((float) $original['customer_delta'] + (float) $reversal['customer_delta']) > 0.01
                        || abs((float) $original['supplier_delta'] + (float) $reversal['supplier_delta']) > 0.01) {
                        return false;
                    }

                    return array_intersect($reversalKeys, $this->correlationKeys($original)) !== [];
                })
                ->sortByDesc('business_time')
                ->first();

            if (is_array($candidate)) {
                $reversal['reversal_of_event_identity'] = $candidate['event_identity'];
                $reversal['metadata']['reversal_link_resolution'] = 'opposite_delta_and_persisted_document_key';
            }

            return $reversal;
        })->values();
    }

    /** @return array<int, string> */
    private function correlationKeys(array $event): array
    {
        $metadata = (array) ($event['metadata'] ?? []);
        $keys = [
            (string) ($event['source_type'] ?? '').':'.(string) ($event['source_id'] ?? ''),
            (string) ($event['detail_code'] ?? ''),
            (string) ($metadata['reference_code'] ?? ''),
            (string) ($metadata['document_group_parent_code'] ?? ''),
            (string) ($metadata['parent_document_code'] ?? ''),
        ];

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '' && $key !== ':')));
    }

    private function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '1970-01-01 00:00:00.000000';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
