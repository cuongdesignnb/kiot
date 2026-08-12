<?php

namespace App\Services\Exports;

use App\Exceptions\PartnerDebtExportContractException;

class PartnerDebtExportRunningBalanceResolver
{
    public function resolve(array $entry, string $orientation): float
    {
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
