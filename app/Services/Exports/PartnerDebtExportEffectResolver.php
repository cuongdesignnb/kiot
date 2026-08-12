<?php

namespace App\Services\Exports;

/**
 * Reads the orientation-specific canonical display effect for an export.
 * Generic `amount` is accepted only for legacy rows which carry no canonical
 * identity/effect metadata; canonical rows fail closed instead of silently
 * changing the financial result through a renderer fallback.
 */
class PartnerDebtExportEffectResolver
{
    public function resolve(array $entry, string $orientation): float
    {
        $keys = $orientation === 'supplier'
            ? ['supplier_display_effect', 'supplier_effect', 'display_effect']
            : ['customer_display_effect', 'customer_effect', 'display_effect'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        if ($this->hasCanonicalEvidence($entry)) {
            return 0.0;
        }

        return is_numeric($entry['amount'] ?? null) ? (float) $entry['amount'] : 0.0;
    }

    public function resolveForExport(
        array $entry,
        string $orientation,
        PartnerDebtExportDocumentResolver $documents
    ): float {
        $effect = $this->resolve($entry, $orientation);
        $kind = strtolower((string) ($entry['event_kind'] ?? ''));
        $identity = $documents->resolve($entry);

        if (($identity['document_type'] ?? null) === 'Purchase'
            && ! ($identity['is_payment'] ?? false)
            && in_array($kind, ['purchase', 'purchase_cancel_reversal'], true)
        ) {
            $discount = $documents->purchaseDiscount($entry);
            if ($discount > 0 && $effect !== 0.0) {
                $effect += $effect > 0 ? -$discount : $discount;
            }
        }

        return $effect;
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
