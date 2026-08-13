<?php

namespace App\Services\Exports;

use App\Exceptions\PartnerDebtExportContractException;

class PartnerDebtExportRunningBalanceResolver
{
    /**
     * Project the export balance from the same effect lens used by the
     * workbook. The timeline is normally newest-first, so calculation is
     * chronological and then mapped back to display order.
     *
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<int,array<string,mixed>>
     */
    public function project(
        array $entries,
        string $orientation,
        PartnerDebtExportEffectResolver $effects,
        PartnerDebtExportDocumentResolver $documents,
    ): array {
        $ordered = [];
        foreach (array_values($entries) as $index => $entry) {
            $entry['_export_original_index'] = $index;
            $ordered[] = $entry;
        }

        usort($ordered, function (array $left, array $right): int {
            foreach (['event_sort_time', 'business_time', 'display_time', 'time', 'created_at'] as $key) {
                $comparison = strcmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            foreach (['balance_order', 'event_order', 'display_sequence'] as $key) {
                $comparison = ((int) ($left[$key] ?? 999)) <=> ((int) ($right[$key] ?? 999));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $comparison = strcmp(
                (string) ($left['event_identity'] ?? $left['code'] ?? ''),
                (string) ($right['event_identity'] ?? $right['code'] ?? ''),
            );

            return $comparison !== 0
                ? $comparison
                : ((int) $left['_export_original_index'] <=> (int) $right['_export_original_index']);
        });

        $running = 0.0;
        $projected = array_fill(0, count($ordered), []);
        foreach ($ordered as $entry) {
            $effect = $effects->resolveForExport($entry, $orientation, $documents);
            $running += $effect;
            $entry['export_effect'] = $effect;
            $entry['export_running_balance'] = $running;
            $index = (int) $entry['_export_original_index'];
            unset($entry['_export_original_index']);
            $projected[$index] = $entry;
        }

        return $projected;
    }

    public function resolve(array $entry, string $orientation): float
    {
        if (array_key_exists('export_running_balance', $entry)
            && is_numeric($entry['export_running_balance'])) {
            return (float) $entry['export_running_balance'];
        }

        $prefix = $orientation === 'supplier' ? 'supplier' : 'customer';
        foreach ([
            $prefix.'_display_running_balance',
            $prefix.'_running_balance',
        ] as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        if ($this->hasCanonicalEvidence($entry)) {
            throw new PartnerDebtExportContractException('missing_orientation_running_balance', [
                'event_identity' => (string) ($entry['event_identity'] ?? ''),
                'event_kind' => (string) ($entry['event_kind'] ?? ''),
                'reference_type' => (string) ($entry['reference_type'] ?? ''),
                'reference_id' => (string) ($entry['reference_id'] ?? ''),
                'orientation' => $orientation,
                'available_running_balance_fields' => array_values(array_filter(
                    array_keys($entry),
                    static fn (string $key): bool => str_contains($key, 'running_balance') || str_contains($key, 'debt_remain'),
                )),
            ]);
        }

        foreach (['running_balance', 'debt_remain'] as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        return 0.0;
    }

    private function hasCanonicalEvidence(array $entry): bool
    {
        return ($entry['event_identity'] ?? null) !== null
            || ($entry['reference_type'] ?? null) !== null
            || ($entry['reference_id'] ?? null) !== null
            || ($entry['source_type'] ?? null) !== null
            || ($entry['source_id'] ?? null) !== null
            || ($entry['canonical'] ?? false)
            || array_key_exists('affects_canonical_balance', $entry);
    }
}
