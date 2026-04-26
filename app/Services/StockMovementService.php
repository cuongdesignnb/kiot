<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 4 — Stock Movement Service.
 *
 * Ghi nhận từng lần thay đổi tồn kho thành 1 row stock_movements.
 * KHÔNG sửa product.stock_quantity hay product.cost_price ở đây
 * (việc đó vẫn do CostingService / controller hiện tại đảm nhiệm).
 *
 * Chỉ snapshot balance_qty/balance_cost SAU khi controller đã update product.
 *
 * Cách dùng: Sau mỗi pha controller update stock thành công, gọi
 *   StockMovementService::record(...)
 */
class StockMovementService
{
    /**
     * Type constants.
     */
    public const TYPE_IN_PURCHASE = 'in_purchase';
    public const TYPE_OUT_INVOICE = 'out_invoice';
    public const TYPE_IN_INVOICE_RETURN = 'in_invoice_return'; // KH trả hàng → tăng tồn
    public const TYPE_OUT_PURCHASE_RETURN = 'out_purchase_return'; // trả NCC → giảm tồn
    public const TYPE_ADJUST_IN = 'adjust_in';
    public const TYPE_ADJUST_OUT = 'adjust_out';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_REPAIR_IN = 'repair_in';
    public const TYPE_REPAIR_OUT = 'repair_out';

    /**
     * Ghi nhận 1 movement.
     *
     * @param Product $product Product đã được update stock_quantity/cost_price (refresh trước khi gọi)
     * @param string $type Một trong các const TYPE_*
     * @param int $qty Số lượng (luôn dương)
     * @param float $unitCost Giá vốn đơn vị tại thời điểm dịch chuyển
     * @param Model|null $ref Chứng từ gốc (Purchase, Invoice, ...)
     * @param array $opts Tùy chọn: serial_imei_id, branch_id, ref_code, note, moved_at, user_id, employee_id
     */
    public static function record(
        Product $product,
        string $type,
        int $qty,
        float $unitCost,
        ?Model $ref = null,
        array $opts = []
    ): StockMovement {
        $direction = self::directionFor($type);
        $qty = abs($qty);

        // Refresh để lấy balance hiện tại (giả định controller đã update xong)
        $product->refresh();

        $balanceQty = (int) $product->stock_quantity;
        $balanceCost = (float) $product->cost_price;

        $user = Auth::user();
        $userId = $opts['user_id'] ?? $user?->id;
        $employeeId = $opts['employee_id'] ?? $user?->employee?->id;

        return StockMovement::create([
            'product_id' => $product->id,
            'serial_imei_id' => $opts['serial_imei_id'] ?? null,
            'branch_id' => $opts['branch_id'] ?? null,

            'type' => $type,
            'direction' => $direction,

            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $qty * $unitCost,

            'balance_qty' => $balanceQty,
            'balance_cost' => $balanceCost,

            'ref_type' => $ref ? get_class($ref) : null,
            'ref_id' => $ref?->id,
            'ref_code' => $opts['ref_code'] ?? ($ref->code ?? null),

            'user_id' => $userId,
            'employee_id' => $employeeId,

            'note' => $opts['note'] ?? null,
            'moved_at' => $opts['moved_at'] ?? now(),
        ]);
    }

    /**
     * Ghi nhận nhiều serial cùng lúc (cho hàng có serial).
     */
    public static function recordSerials(
        Product $product,
        string $type,
        array $serialImeiIds,
        float $unitCostPerSerial,
        ?Model $ref = null,
        array $opts = []
    ): array {
        $movements = [];
        foreach ($serialImeiIds as $sid) {
            $movements[] = self::record($product, $type, 1, $unitCostPerSerial, $ref, array_merge($opts, [
                'serial_imei_id' => $sid,
            ]));
        }
        return $movements;
    }

    private static function directionFor(string $type): string
    {
        return match ($type) {
            self::TYPE_IN_PURCHASE,
            self::TYPE_IN_INVOICE_RETURN,
            self::TYPE_ADJUST_IN,
            self::TYPE_TRANSFER_IN,
            self::TYPE_REPAIR_IN => 'in',
            default => 'out',
        };
    }
}
