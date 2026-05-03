<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Service tính giá vốn theo phương pháp BÌNH QUÂN DI ĐỘNG (Moving Weighted Average).
 *
 * Nguồn dữ liệu:
 *  - products.inventory_total_cost: tổng giá trị tồn kho (ledger ngân quỹ).
 *  - products.stock_quantity: tổng số đơn vị tồn.
 *  - products.cost_price = inventory_total_cost / stock_quantity (BQ).
 *
 * Per-IMEI cost_price chỉ phục vụ HIỂN THỊ (giá vốn cuối + chênh lệch sửa chữa),
 * KHÔNG ảnh hưởng COGS hay BQ sản phẩm.
 *
 * Quy ước:
 *  - Nhập hàng (Sn @ Gn): BQ_mới = (St·Gt + Sn·Gn) / (St + Sn)
 *  - Bán hàng: COGS = BQ hiện tại; total_cost giảm theo BQ; BQ KHÔNG đổi.
 *  - KH trả: phục hồi với cost lúc bán (snapshot trên invoice_item.cost_price).
 *  - Trả NCC / hủy nhập: rút khỏi tồn theo cost lúc nhập.
 *  - Sửa chữa (parts cost ΔC vào 1 IMEI): total_cost += ΔC; BQ tăng theo ΔC/qty.
 *
 * Tất cả hàm đều CHỐT giao dịch trên product và TRẢ VỀ cost_price (BQ) sau khi áp dụng.
 */
class MovingAvgCostingService
{
    /**
     * Áp dụng nhập hàng. Trả về BQ mới.
     *
     * @param Product  $product
     * @param int      $qty       Số lượng nhập (>0)
     * @param float    $unitCost  Giá vốn 1 đơn vị (đã phân bổ phí nhập nếu có)
     */
    public static function applyPurchase(Product $product, int $qty, float $unitCost): float
    {
        if ($qty <= 0) {
            return (float) $product->cost_price;
        }

        return DB::transaction(function () use ($product, $qty, $unitCost) {
            $product = Product::lockForUpdate()->find($product->id);
            $oldQty = max(0, (int) $product->stock_quantity);
            $oldTotal = (float) ($product->inventory_total_cost ?? 0);

            $newQty = $oldQty + $qty;
            $newTotal = $oldTotal + ($qty * $unitCost);
            $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;

            $product->stock_quantity = $newQty;
            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->last_purchase_price = $unitCost;
            $product->save();

            return $newAvg;
        });
    }

    /**
     * Áp dụng bán hàng. COGS = BQ hiện tại. Trả về [cogs_per_unit, cogs_total].
     */
    public static function applySale(Product $product, int $qty): array
    {
        if ($qty <= 0) {
            return ['cogs_per_unit' => (float) $product->cost_price, 'cogs_total' => 0.0];
        }

        return DB::transaction(function () use ($product, $qty) {
            $product = Product::lockForUpdate()->find($product->id);
            $bq = (float) $product->cost_price;
            $cogsTotal = round($bq * $qty, 2);

            $newQty = max(0, (int) $product->stock_quantity - $qty);
            $newTotal = max(0.0, (float) $product->inventory_total_cost - $cogsTotal);
            // BQ không đổi khi rút ra ở giá BQ; nếu hết tồn → BQ giữ nguyên (để không reset 0).
            $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;

            $product->stock_quantity = $newQty;
            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->save();

            return ['cogs_per_unit' => $bq, 'cogs_total' => $cogsTotal];
        });
    }

    /**
     * Áp dụng KH trả hàng (đảo ngược 1 phần hóa đơn).
     * Phục hồi tồn ở cost lúc bán (snapshot trên invoice_item.cost_price).
     */
    public static function applySaleReturn(Product $product, int $qty, float $costAtSale): float
    {
        if ($qty <= 0) {
            return (float) $product->cost_price;
        }

        return DB::transaction(function () use ($product, $qty, $costAtSale) {
            $product = Product::lockForUpdate()->find($product->id);
            $oldQty = max(0, (int) $product->stock_quantity);
            $oldTotal = (float) ($product->inventory_total_cost ?? 0);

            $newQty = $oldQty + $qty;
            $newTotal = $oldTotal + ($qty * $costAtSale);
            $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;

            $product->stock_quantity = $newQty;
            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->save();

            return $newAvg;
        });
    }

    /**
     * Áp dụng trả NCC / hủy phiếu nhập.
     * Rút khỏi tồn tại cost lúc nhập (snapshot trên purchase_item.unit_cost_allocated).
     */
    public static function applyPurchaseReturn(Product $product, int $qty, float $costAtPurchase): float
    {
        if ($qty <= 0) {
            return (float) $product->cost_price;
        }

        return DB::transaction(function () use ($product, $qty, $costAtPurchase) {
            $product = Product::lockForUpdate()->find($product->id);
            $oldQty = max(0, (int) $product->stock_quantity);
            $oldTotal = (float) ($product->inventory_total_cost ?? 0);

            $newQty = max(0, $oldQty - $qty);
            $newTotal = max(0.0, $oldTotal - ($qty * $costAtPurchase));
            // RR-05: nhất quán với applySale — khi tồn về 0, giữ BQ cuối làm last-known average
            $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;

            $product->stock_quantity = $newQty;
            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->save();

            return $newAvg;
        });
    }

    /**
     * Áp dụng điều chỉnh giá vốn từ sửa chữa (parts cost ± ΔC trên 1 IMEI/lô).
     * Chỉ thay đổi total + BQ; KHÔNG đổi qty.
     *
     * @param float $deltaTotal  Tổng tiền cộng vào (linh kiện lắp thêm) hoặc trừ ra (bóc tách).
     */
    public static function applyRepairAdjustment(Product $product, float $deltaTotal): float
    {
        if (abs($deltaTotal) < 0.01) {
            return (float) $product->cost_price;
        }

        return DB::transaction(function () use ($product, $deltaTotal) {
            $product = Product::lockForUpdate()->find($product->id);
            $qty = max(0, (int) $product->stock_quantity);
            $oldTotal = (float) ($product->inventory_total_cost ?? 0);

            $newTotal = max(0.0, $oldTotal + $deltaTotal);
            $newAvg = $qty > 0 ? round($newTotal / $qty, 2) : (float) $product->cost_price;

            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->save();

            return $newAvg;
        });
    }

    /**
     * Khi điều chỉnh kiểm kê (stocktake): rút/thêm tồn ở BQ hiện tại.
     */
    public static function applyAdjustment(Product $product, int $deltaQty): float
    {
        if ($deltaQty === 0) {
            return (float) $product->cost_price;
        }

        return DB::transaction(function () use ($product, $deltaQty) {
            $product = Product::lockForUpdate()->find($product->id);
            $bq = (float) $product->cost_price;
            $oldQty = max(0, (int) $product->stock_quantity);
            $oldTotal = (float) ($product->inventory_total_cost ?? 0);

            $newQty = max(0, $oldQty + $deltaQty);
            $newTotal = max(0.0, $oldTotal + ($deltaQty * $bq));
            $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : $bq;

            $product->stock_quantity = $newQty;
            $product->inventory_total_cost = round($newTotal, 2);
            $product->cost_price = $newAvg;
            $product->save();

            return $newAvg;
        });
    }
}
