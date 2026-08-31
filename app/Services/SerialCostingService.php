<?php

namespace App\Services;

use App\Models\SerialImei;
use Illuminate\Support\Collection;

/**
 * Cost snapshots for serial/IMEI inventory.
 *
 * Serial-tracked products use the cost carried by the selected serials, not
 * the product's moving-average projection. The product projection is only a
 * roll-up of serials that are currently in stock.
 */
final class SerialCostingService
{
    /**
     * @return array{unit_cost: float, total_cost: float, serial_costs: array<int, float>}
     */
    public static function snapshotForSale(Collection $serials): array
    {
        return self::snapshot($serials, false);
    }

    /**
     * @return array{unit_cost: float, total_cost: float, serial_costs: array<int, float>}
     */
    public static function snapshotForReturn(Collection $serials): array
    {
        return self::snapshot($serials, true);
    }

    /**
     * @return array{unit_cost: float, total_cost: float, serial_costs: array<int, float>}
     */
    private static function snapshot(Collection $serials, bool $preferSoldSnapshot): array
    {
        $serialCosts = [];

        /** @var SerialImei $serial */
        foreach ($serials as $serial) {
            $rawCost = $preferSoldSnapshot && $serial->sold_cost_price !== null
                ? $serial->sold_cost_price
                : $serial->cost_price;

            $serialCosts[(int) $serial->id] = round((float) $rawCost, 0);
        }

        $totalCost = round(array_sum($serialCosts), 2);
        $quantity = count($serialCosts);

        return [
            'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 2) : 0.0,
            'total_cost' => $totalCost,
            'serial_costs' => $serialCosts,
        ];
    }
}
