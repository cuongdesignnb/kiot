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

    public function project(array $timeline, string $orientation): array
    {
        if (! in_array($orientation, ['customer', 'supplier'], true)) {
            throw new \InvalidArgumentException('Unsupported partner debt orientation.');
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

        $publicEntries = $entries
            ->reject(fn (array $entry): bool => $this->isCheckpoint($entry))
            ->map(fn (array $entry): array => $this->withoutPresentationMetadata($entry));

        $publicEntries = $checkpointCount > 0
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

        $timeline['summary'] = array_merge((array) ($timeline['summary'] ?? []), [
            'count' => $publicEntries->count(),
            'entry_count' => $publicEntries->count(),
            'has_virtual_opening_balance' => $hasOpening,
            'virtual_opening_balance' => $opening,
            'hidden_reconciliation_adjustment' => $checkpointOpening,
            'hidden_reconciliation_checkpoint_count' => $checkpointCount,
        ]);
        $timeline['reconcile'] = array_merge((array) ($timeline['reconcile'] ?? []), [
            'has_virtual_opening_balance' => $hasOpening,
            'virtual_opening_balance' => $opening,
            'hidden_reconciliation_adjustment' => $checkpointOpening,
            'hidden_reconciliation_checkpoint_count' => $checkpointCount,
        ]);

        return $timeline;
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
