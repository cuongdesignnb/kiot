<?php

namespace App\Services\Exports;

use App\Exceptions\PartnerDebtExportContractException;

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
        if (($entry['reference_only'] ?? false)
            || ($entry['is_reference_only'] ?? false)
            || (array_key_exists('affects_canonical_balance', $entry) && ! (bool) $entry['affects_canonical_balance'])
            || (array_key_exists('affects_document_balance', $entry) && ! (bool) $entry['affects_document_balance'])
            || (array_key_exists('affects_debt_balance', $entry) && ! (bool) $entry['affects_debt_balance'])) {
            return 0.0;
        }

        $keys = $orientation === 'supplier'
            ? ['supplier_display_effect', 'supplier_effect']
            : ['customer_display_effect', 'customer_effect'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        if ($this->hasCanonicalEvidence($entry)) {
            throw new PartnerDebtExportContractException('missing_orientation_effect', [
                'event_identity' => (string) ($entry['event_identity'] ?? ''),
                'event_kind' => (string) ($entry['event_kind'] ?? ''),
                'reference_type' => (string) ($entry['reference_type'] ?? ''),
                'reference_id' => (string) ($entry['reference_id'] ?? ''),
                'orientation' => $orientation,
                'available_effect_fields' => array_values(array_filter(
                    array_keys($entry),
                    static fn (string $key): bool => str_contains($key, 'effect'),
                )),
            ]);
        }

        if (array_key_exists('display_effect', $entry) && is_numeric($entry['display_effect'])) {
            return (float) $entry['display_effect'];
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
            && (string) ($entry['purchase_effect_basis'] ?? '') !== 'net_supplier_payable'
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
