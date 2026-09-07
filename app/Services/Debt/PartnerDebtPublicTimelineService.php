<?php

namespace App\Services\Debt;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Converts the canonical audit timeline into the operator-facing debt view.
 *
 * Canonical events remain untouched. Synthetic reconciliation checkpoints are
 * folded into an opening balance so public rows, pagination and exports only
 * contain real business documents. Presentation-only notes and badges are
 * removed here; source documents keep their persisted notes for detail views.
 */
class PartnerDebtPublicTimelineService
{
    private const TOLERANCE = 0.0001;

    private const CANCELLATION_SCOPES = ['active', 'cancelled', 'all'];

    private const CANCELLED_DOCUMENT_EVENTS = [
        'invoice_cancel_reversal',
        'purchase_cancel_reversal',
        'sales_return_cancel_reversal',
        'purchase_return_cancel_reversal',
    ];

    public function project(array $timeline, string $orientation, string $cancellationScope = 'active'): array
    {
        if (! in_array($orientation, ['customer', 'supplier'], true)) {
            throw new \InvalidArgumentException('Unsupported partner debt orientation.');
        }
        if (! in_array($cancellationScope, self::CANCELLATION_SCOPES, true)) {
            throw new \InvalidArgumentException('Unsupported partner debt cancellation scope.');
        }

        $entries = collect($timeline['entries'] ?? [])
            ->map(fn ($entry): array => is_array($entry) ? $entry : (array) $entry);

        $checkpoints = $entries->filter(fn (array $entry): bool => $this->isCheckpoint($entry));
        $customerCheckpointOpening = (float) $checkpoints->sum(
            fn (array $entry): float => $this->effect($entry, 'customer'),
        );
        $supplierCheckpointOpening = (float) $checkpoints->sum(
            fn (array $entry): float => $this->effect($entry, 'supplier'),
        );
        $checkpointCount = $checkpoints->count();

        $businessEntries = $entries
            ->reject(fn (array $entry): bool => $this->isCheckpoint($entry));
        $cancelledDocumentGroups = $this->cancelledDocumentGroups($businessEntries);
        $standaloneCancelledIdentities = $this->standaloneCancelledIdentities($businessEntries);

        $visibleEntries = match ($cancellationScope) {
            'active' => $businessEntries
                ->reject(fn (array $entry): bool => $this->hiddenFromActiveTimeline(
                    $entry,
                    $cancelledDocumentGroups,
                    $standaloneCancelledIdentities,
                ))
                ->map(fn (array $entry): array => $this->detachIndependentPaymentFromCancelledDocument(
                    $entry,
                    $cancelledDocumentGroups,
                    $orientation,
                )),
            'cancelled' => $businessEntries->filter(fn (array $entry): bool => $this->belongsToCancelledEvidence(
                $entry,
                $cancelledDocumentGroups,
                $standaloneCancelledIdentities,
            )),
            default => $businessEntries,
        };

        $publicEntries = $visibleEntries
            ->map(fn (array $entry): array => $this->withoutPresentationMetadata($entry));

        $mustReproject = $checkpointCount > 0 || $cancellationScope === 'active';
        $publicEntries = $mustReproject
            ? $this->reprojectRunningBalances(
                $publicEntries,
                $orientation,
                $customerCheckpointOpening,
                $supplierCheckpointOpening,
            )
            : $this->sortNewestFirst($publicEntries);

        $checkpointOpening = $orientation === 'customer'
            ? $customerCheckpointOpening
            : $supplierCheckpointOpening;
        $existingOpening = (float) (($timeline['summary']['virtual_opening_balance'] ?? null)
            ?? ($timeline['virtual_opening_balance'] ?? 0));
        $opening = $existingOpening + $checkpointOpening;
        $hasOpening = abs($opening) > self::TOLERANCE;

        $timeline['entries'] = $publicEntries->all();
        $timeline['entry_count'] = $publicEntries->count();
        $timeline['public_opening_balance'] = $opening;
        $timeline['hidden_reconciliation_adjustment'] = $checkpointOpening;
        $timeline['hidden_reconciliation_checkpoint_count'] = $checkpointCount;
        $timeline['cancellation_scope'] = $cancellationScope;
        $timeline['cancelled_document_count'] = count($cancelledDocumentGroups);
        $timeline['hidden_cancelled_entry_count'] = $cancellationScope === 'active'
            ? $businessEntries->count() - $visibleEntries->count()
            : 0;

        $timeline['summary'] = array_merge((array) ($timeline['summary'] ?? []), [
            'count' => $publicEntries->count(),
            'entry_count' => $publicEntries->count(),
            'has_virtual_opening_balance' => $hasOpening,
            'virtual_opening_balance' => $opening,
            'hidden_reconciliation_adjustment' => $checkpointOpening,
            'hidden_reconciliation_checkpoint_count' => $checkpointCount,
            'cancellation_scope' => $cancellationScope,
            'cancelled_document_count' => count($cancelledDocumentGroups),
            'hidden_cancelled_entry_count' => $timeline['hidden_cancelled_entry_count'],
        ]);
        $timeline['reconcile'] = array_merge((array) ($timeline['reconcile'] ?? []), [
            'has_virtual_opening_balance' => $hasOpening,
            'virtual_opening_balance' => $opening,
            'hidden_reconciliation_adjustment' => $checkpointOpening,
            'hidden_reconciliation_checkpoint_count' => $checkpointCount,
        ]);

        return $timeline;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, true>
     */
    private function cancelledDocumentGroups(Collection $entries): array
    {
        return $entries
            ->filter(fn (array $entry): bool => in_array(
                (string) ($entry['event_kind'] ?? ''),
                self::CANCELLED_DOCUMENT_EVENTS,
                true,
            ))
            ->mapWithKeys(function (array $entry): array {
                $key = $this->documentGroupIdentity($entry);

                return $key === null ? [] : [$key => true];
            })
            ->all();
    }

    /**
     * Standalone vouchers own their own cancellation. Source-document payment
     * reversals merely release an allocation and must not make a separately
     * created receipt/payment disappear with the invoice or purchase.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, true>
     */
    private function standaloneCancelledIdentities(Collection $entries): array
    {
        return $entries
            ->filter(fn (array $entry): bool => $this->isCancellationEvent($entry)
                && (string) ($entry['source_table'] ?? '') === 'cash_flows'
                && ! in_array((string) ($entry['reference_type'] ?? ''), ['Invoice', 'Purchase'], true))
            ->map(fn (array $entry): string => trim((string) ($entry['reversal_of'] ?? '')))
            ->filter()
            ->mapWithKeys(fn (string $identity): array => [$identity => true])
            ->all();
    }

    /** @param array<string, true> $cancelledDocumentGroups */
    private function hiddenFromActiveTimeline(
        array $entry,
        array $cancelledDocumentGroups,
        array $standaloneCancelledIdentities,
    ): bool {
        if ($this->isCancellationEvent($entry)) {
            return true;
        }

        $identity = trim((string) ($entry['event_identity'] ?? ''));
        if ($identity !== '' && isset($standaloneCancelledIdentities[$identity])) {
            return true;
        }

        $group = $this->documentGroupIdentity($entry);
        if ($group === null || ! isset($cancelledDocumentGroups[$group])) {
            return false;
        }

        if ($this->isIndependentPayment($entry)) {
            return false;
        }

        return $this->isDocumentEvent($entry)
            || $this->isSourceDocumentPayment($entry)
            || (bool) ($entry['is_virtual_fallback'] ?? false);
    }

    /**
     * @param  array<string, true>  $cancelledDocumentGroups
     * @param  array<string, true>  $standaloneCancelledIdentities
     */
    private function belongsToCancelledEvidence(
        array $entry,
        array $cancelledDocumentGroups,
        array $standaloneCancelledIdentities,
    ): bool {
        if ($this->isCancellationEvent($entry)) {
            return true;
        }

        $identity = trim((string) ($entry['event_identity'] ?? ''));
        if ($identity !== '' && isset($standaloneCancelledIdentities[$identity])) {
            return true;
        }

        $group = $this->documentGroupIdentity($entry);

        return $group !== null && isset($cancelledDocumentGroups[$group]);
    }

    /** @param array<string, true> $cancelledDocumentGroups */
    private function detachIndependentPaymentFromCancelledDocument(
        array $entry,
        array $cancelledDocumentGroups,
        string $orientation,
    ): array {
        $group = $this->documentGroupIdentity($entry);
        if ($group === null
            || ! isset($cancelledDocumentGroups[$group])
            || ! $this->isIndependentPayment($entry)) {
            return $entry;
        }

        foreach ([
            'document_group_key',
            'document_group_type',
            'document_group_parent_code',
            'parent_document_code',
            'payment_for_code',
            'linked_document_code',
            'linked_document_label',
            'sort_group_key',
            'sort_group_time',
            'sort_group_sequence',
        ] as $key) {
            unset($entry[$key]);
        }

        $entry['display_type'] = $orientation === 'customer'
            ? 'Khách thanh toán'
            : 'Thanh toán NCC';
        $entry['type_label'] = $entry['display_type'];
        $entry['released_from_cancelled_document'] = true;

        return $entry;
    }

    private function isCancellationEvent(array $entry): bool
    {
        return str_ends_with((string) ($entry['event_kind'] ?? ''), '_cancel_reversal');
    }

    private function isDocumentEvent(array $entry): bool
    {
        return in_array((string) ($entry['event_kind'] ?? ''), [
            'customer_sale',
            'purchase',
            'sales_return',
            'purchase_return',
        ], true);
    }

    private function isSourceDocumentPayment(array $entry): bool
    {
        return (string) ($entry['payment_origin'] ?? '') === 'source_document';
    }

    private function isIndependentPayment(array $entry): bool
    {
        return in_array((string) ($entry['payment_origin'] ?? ''), [
            'standalone',
            'order_deposit',
        ], true);
    }

    private function documentGroupIdentity(array $entry): ?string
    {
        $isDocumentCancellation = in_array(
            (string) ($entry['event_kind'] ?? ''),
            self::CANCELLED_DOCUMENT_EVENTS,
            true,
        );
        // Canonical orientation intentionally groups a reversal under its own
        // HUY-* display code. For visibility filtering, however, the group is
        // the original business document carried by reference_code.
        $code = trim((string) ($isDocumentCancellation
            ? ($entry['reference_code']
                ?? $entry['document_group_parent_code']
                ?? $entry['document_group_key']
                ?? '')
            : ($entry['document_group_key']
                ?? $entry['document_group_parent_code']
                ?? $entry['parent_document_code']
                ?? $entry['reference_code']
                ?? '')));
        if ($code === '') {
            return null;
        }

        $type = strtolower(trim((string) ($entry['document_group_type'] ?? '')));
        if ($type === '') {
            $type = match ((string) ($entry['reference_type'] ?? '')) {
                'Invoice' => 'invoice',
                'Purchase' => 'purchase',
                'OrderReturn' => 'sales_return',
                'PurchaseReturn' => 'purchase_return',
                default => 'other',
            };
        }

        return $type.':'.strtoupper($code);
    }

    public function isCheckpoint(array $entry): bool
    {
        return (bool) ($entry['is_reconciliation_checkpoint'] ?? false)
            || (string) ($entry['event_kind'] ?? '') === 'persisted_ledger_checkpoint'
            || str_starts_with((string) ($entry['code'] ?? ''), 'CHECKPOINT-');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function reprojectRunningBalances(
        Collection $entries,
        string $orientation,
        float $customerOpening,
        float $supplierOpening,
    ): Collection {
        $customerRunning = $customerOpening;
        $supplierRunning = $supplierOpening;

        return $entries
            ->sort(fn (array $left, array $right): int => $this->compareChronologically($left, $right))
            ->map(function (array $entry) use (
                &$customerRunning,
                &$supplierRunning,
                $orientation,
            ): array {
                if ($this->affectsBalance($entry)) {
                    $customerRunning += $this->effect($entry, 'customer');
                    $supplierRunning += $this->effect($entry, 'supplier');
                }

                $running = $orientation === 'customer' ? $customerRunning : $supplierRunning;

                return array_merge($entry, [
                    'customer_running_balance' => $customerRunning,
                    'supplier_running_balance' => $supplierRunning,
                    'customer_display_running_balance' => $customerRunning,
                    'supplier_display_running_balance' => $supplierRunning,
                    'running_balance' => $running,
                    'debt_remain' => $running,
                    'balance' => $running,
                ]);
            })
            ->reverse()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function sortNewestFirst(Collection $entries): Collection
    {
        return $entries
            ->sort(fn (array $left, array $right): int => $this->compareChronologically($left, $right))
            ->reverse()
            ->values();
    }

    private function compareChronologically(array $left, array $right): int
    {
        $timeComparison = $this->sortTimestamp($left) <=> $this->sortTimestamp($right);
        if ($timeComparison !== 0) {
            return $timeComparison;
        }

        $orderComparison = (int) ($left['event_order'] ?? 0) <=> (int) ($right['event_order'] ?? 0);
        if ($orderComparison !== 0) {
            return $orderComparison;
        }

        return strcmp(
            (string) ($left['event_identity'] ?? $left['id'] ?? $left['code'] ?? ''),
            (string) ($right['event_identity'] ?? $right['id'] ?? $right['code'] ?? ''),
        );
    }

    private function sortTimestamp(array $entry): int
    {
        $value = $entry['business_time']
            ?? $entry['event_sort_time']
            ?? $entry['display_time']
            ?? $entry['time']
            ?? $entry['transaction_date']
            ?? $entry['purchase_date']
            ?? $entry['return_date']
            ?? $entry['created_at']
            ?? null;

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        $raw = trim((string) $value);
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $raw);
                if ($parsed !== false && $parsed->format($format) === $raw) {
                    return $parsed->getTimestamp();
                }
            } catch (\Throwable) {
                // Fall through to the next exact format.
            }
        }

        try {
            return Carbon::parse($raw)->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function affectsBalance(array $entry): bool
    {
        if ((bool) ($entry['is_virtual_opening'] ?? false)
            || (string) ($entry['event_kind'] ?? '') === 'virtual_opening_balance') {
            return true;
        }

        if ((bool) ($entry['reference_only'] ?? $entry['is_reference_only'] ?? false)) {
            return false;
        }

        foreach (['affects_canonical_balance', 'affects_document_balance', 'affects_debt_balance'] as $key) {
            if (array_key_exists($key, $entry) && ! (bool) $entry[$key]) {
                return false;
            }
        }

        return true;
    }

    private function effect(array $entry, string $orientation): float
    {
        $keys = $orientation === 'customer'
            ? ['customer_display_effect', 'customer_display_delta', 'customer_effect']
            : ['supplier_display_effect', 'supplier_display_delta', 'supplier_effect'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        return is_numeric($entry['display_effect'] ?? null)
            ? (float) $entry['display_effect']
            : (is_numeric($entry['amount'] ?? null) ? (float) $entry['amount'] : 0.0);
    }

    private function withoutPresentationMetadata(array $entry): array
    {
        foreach ([
            'badge_label',
            'badge_title',
            'balance_note',
            'note',
            'description',
            'payment_allocation_note',
        ] as $key) {
            unset($entry[$key]);
        }

        return $entry;
    }
}
