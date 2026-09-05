<?php

namespace App\Services\Debt;

use App\Models\Purchase;
use RuntimeException;

/** Keeps acquisition cost separate from the amount owed to the supplier. */
class PurchasePayableService
{
    public const EXTERNAL_COST_TARGET_TYPES = ['Chi phí', 'Chi phi'];

    public function costItems(Purchase $purchase): array
    {
        $items = $purchase->other_costs;
        // Old imports contain JSON encoded more than once. Read it without
        // rewriting production rows or silently dropping the cost on edit.
        for ($i = 0; is_string($items) && $i < 4; $i++) {
            $items = json_decode($items, true, flags: JSON_THROW_ON_ERROR);
        }
        if ($items !== null && ! is_array($items)) {
            throw new RuntimeException('Purchase '.$purchase->code.': invalid purchase cost details.');
        }

        return $items ?? [];
    }

    public function amount(Purchase $purchase): float
    {
        return $this->forAmounts(
            $purchase,
            (float) $purchase->total_amount,
            (float) $purchase->discount,
            (float) $purchase->other_costs_total,
        );
    }

    public function forAmounts(Purchase $purchase, float $goods, float $discount, float $costs): float
    {
        $external = $this->externalCostAmount($purchase);
        if ($external > $costs + 0.01) {
            throw new RuntimeException('Purchase '.$purchase->code.': external expense evidence exceeds purchase costs; reconcile expense vouchers before changing costs.');
        }

        return round($goods - $discount + $costs - $external, 2);
    }

    public function externalCostAmount(Purchase $purchase): float
    {
        if (! $purchase->exists) {
            return 0.0;
        }

        // These vouchers prove the payee, not a supplier payment. Keep historical
        // (including cancelled/deleted) evidence so cancellation reverses the
        // original supplier obligation instead of changing it retrospectively.
        return round((float) $purchase->externalCostPayments->sum('amount'), 2);
    }
}
