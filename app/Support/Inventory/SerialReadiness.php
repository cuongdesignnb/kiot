<?php

namespace App\Support\Inventory;

use App\Models\SerialImei;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "is this serial ready to sell?".
 *
 * A serial counts as stock only while `status = in_stock`. Among those, a
 * serial whose repair workflow has not finished (`not_started` / `repairing`)
 * is "repairing"; everything else (NULL, `ready`, legacy values) is "ready".
 * Used by the Website PC provider and the read-only inventory API so both
 * report identical ready/repairing counts.
 */
final class SerialReadiness
{
    public const IN_STOCK_STATUS = 'in_stock';

    /** Repair statuses that keep an in-stock serial out of the sellable pool. */
    public const NOT_READY_REPAIR_STATUSES = ['not_started', 'repairing'];

    public static function scopeReady(Builder $serials): Builder
    {
        return $serials
            ->where('status', self::IN_STOCK_STATUS)
            ->where(function (Builder $query) {
                $query->whereNull('repair_status')->orWhereNotIn('repair_status', self::NOT_READY_REPAIR_STATUSES);
            });
    }

    public static function scopeRepairing(Builder $serials): Builder
    {
        return $serials
            ->where('status', self::IN_STOCK_STATUS)
            ->whereIn('repair_status', self::NOT_READY_REPAIR_STATUSES);
    }

    /**
     * Adds `ready_serial_quantity` and `repairing_serial_quantity` sub-selects
     * to a products query.
     */
    public static function addProductCountSelects(Builder $products): Builder
    {
        return $products
            ->selectSub(
                self::scopeReady(SerialImei::query()->selectRaw('COUNT(*)')->whereColumn('product_id', 'products.id')),
                'ready_serial_quantity'
            )
            ->selectSub(
                self::scopeRepairing(SerialImei::query()->selectRaw('COUNT(*)')->whereColumn('product_id', 'products.id')),
                'repairing_serial_quantity'
            );
    }

    /** Readiness of a single serial row: ready | repairing | not_in_stock. */
    public static function readinessOf(?string $status, ?string $repairStatus): string
    {
        if ($status !== self::IN_STOCK_STATUS) {
            return 'not_in_stock';
        }

        return in_array($repairStatus, self::NOT_READY_REPAIR_STATUSES, true) ? 'repairing' : 'ready';
    }
}
