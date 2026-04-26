<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;

/**
 * Tập trung logic giá vốn bình quân gia quyền & đảo ngược.
 *
 * Quy ước:
 * - Hàng thường: dùng product.cost_price (avg) + product.stock_quantity.
 * - Hàng có serial/IMEI: KHÔNG dùng các công thức trong service này; thay vào đó
 *   gọi $product->recomputeFromSerials() — giá vốn = avg(cost_price) của serial in_stock.
 */
class CostingService
{
    /** Có dùng phương pháp bình quân không? */
    public static function isAverageMethod(): bool
    {
        return Setting::get('inventory_costing_method', 'average') === 'average';
    }

    /**
     * Phân bổ phí nhập (other_costs_total) vào từng dòng theo tỉ lệ subtotal.
     *
     * @param array $items Mỗi item phải có 'quantity', 'price', 'discount'
     * @param float $otherCostsTotal Tổng chi phí nhập khác cần phân bổ
     * @return array<int, array{subtotal: float, allocated_fee: float, unit_cost_allocated: float}>
     *         Trả về theo cùng thứ tự index của $items.
     */
    public static function allocateOtherCosts(array $items, float $otherCostsTotal): array
    {
        $subs = [];
        $totalGoodsValue = 0.0;
        foreach ($items as $i => $it) {
            $qty = (float) ($it['quantity'] ?? 0);
            $price = (float) ($it['price'] ?? 0);
            $disc = (float) ($it['discount'] ?? 0);
            $sub = max(0.0, $qty * $price - $disc);
            $subs[$i] = ['qty' => $qty, 'price' => $price, 'subtotal' => $sub];
            $totalGoodsValue += $sub;
        }

        $result = [];
        $allocatedSoFar = 0.0;
        $lastIdx = array_key_last($subs);

        foreach ($subs as $i => $row) {
            // Tránh sai số làm tròn: dòng cuối lấy phần còn lại.
            if ($i === $lastIdx) {
                $fee = round($otherCostsTotal - $allocatedSoFar, 2);
            } elseif ($totalGoodsValue > 0 && $otherCostsTotal > 0) {
                $fee = round($otherCostsTotal * $row['subtotal'] / $totalGoodsValue, 2);
                $allocatedSoFar += $fee;
            } else {
                $fee = 0.0;
            }

            $unitCost = $row['qty'] > 0
                ? round(($row['subtotal'] + $fee) / $row['qty'], 2)
                : (float) $row['price'];

            $result[$i] = [
                'subtotal' => $row['subtotal'],
                'allocated_fee' => $fee,
                'unit_cost_allocated' => $unitCost,
            ];
        }

        return $result;
    }

    /**
     * Bình quân gia quyền khi nhập (hàng thường).
     * new_avg = (old_qty * old_avg + qty_in * unit_cost) / (old_qty + qty_in)
     */
    public static function recalcOnIn(Product $product, float $qtyIn, float $unitCost): void
    {
        if (!self::isAverageMethod() || $product->has_serial) {
            return;
        }

        $oldQty = (float) $product->stock_quantity;
        $oldCost = (float) $product->cost_price;
        $newQty = $oldQty + $qtyIn;

        if ($newQty > 0) {
            $product->cost_price = round(($oldQty * $oldCost + $qtyIn * $unitCost) / $newQty, 2);
        }
    }

    /**
     * Đảo ngược "nhập" — dùng khi: trả NCC, xóa phiếu nhập đã hoàn thành.
     * unit_cost ở đây là giá vốn lúc nhập của lô đó (purchase_item.unit_cost_allocated),
     * KHÔNG phải product.cost_price hiện tại.
     *
     * new_avg = (old_qty * old_avg - qty_out * unit_cost) / (old_qty - qty_out)
     */
    public static function reverseOnIn(Product $product, float $qtyOut, float $unitCost): void
    {
        if (!self::isAverageMethod() || $product->has_serial) {
            return;
        }

        $oldQty = (float) $product->stock_quantity;
        $oldCost = (float) $product->cost_price;
        $newQty = $oldQty - $qtyOut;

        if ($newQty > 0) {
            $newValue = max(0.0, $oldQty * $oldCost - $qtyOut * $unitCost);
            $product->cost_price = round($newValue / $newQty, 2);
        } elseif ($newQty <= 0) {
            // Hết hàng: reset cost = 0 để lô nhập sau set lại.
            $product->cost_price = 0;
        }
    }

    /**
     * Đảo ngược "xuất" — dùng khi: trả hàng bán, hủy hóa đơn.
     * unit_cost = invoice_item.cost_price (snapshot lúc bán), không phải cost hiện tại.
     *
     * new_avg = (old_qty * old_avg + qty_back * unit_cost) / (old_qty + qty_back)
     * (Công thức gộp giống recalcOnIn nhưng dùng cost gốc lúc bán.)
     */
    public static function reverseOnOut(Product $product, float $qtyBack, float $unitCost): void
    {
        if (!self::isAverageMethod() || $product->has_serial) {
            return;
        }

        $oldQty = (float) $product->stock_quantity;
        $oldCost = (float) $product->cost_price;
        $newQty = $oldQty + $qtyBack;

        if ($newQty > 0) {
            $product->cost_price = round(($oldQty * $oldCost + $qtyBack * $unitCost) / $newQty, 2);
        }
    }
}
