<?php
/**
 * sync_stock.php — Đồng bộ products.stock_quantity = tồn cuối thẻ kho
 * 
 * Dùng CÙNG công thức với inventoryCard() trong ProductController
 * để đảm bảo TỒN KHO header = TỒN CUỐI thẻ kho.
 *
 * Chạy: php sync_stock.php
 * Dry-run (chỉ xem, không sửa): php sync_stock.php --dry-run
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv ?? []);

echo "═══════════════════════════════════════════════\n";
echo "📦 ĐỒNG BỘ stock_quantity = tồn cuối thẻ kho\n";
echo $dryRun ? "   🔍 CHẾ ĐỘ DRY-RUN (chỉ xem, không sửa)\n" : "   ⚡ CHẾ ĐỘ THỰC — SẼ CẬP NHẬT DATABASE\n";
echo "═══════════════════════════════════════════════\n\n";

$products = DB::table('products')->get();
$fixed = 0;
$same = 0;
$total = $products->count();

foreach ($products as $product) {
    $stock = 0;

    // 1. Nhập mua (loại trừ huỷ/draft)
    $stock += (int) DB::table('purchase_items')
        ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
        ->where('purchase_items.product_id', $product->id)
        ->where(function ($q) {
            $q->whereNull('purchases.status')
              ->orWhereNotIn('purchases.status', ['cancelled', 'draft']);
        })
        ->sum('purchase_items.quantity');

    // 2. Bán hàng (không cancelled)
    $stock -= (int) DB::table('invoice_items')
        ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->where('invoice_items.product_id', $product->id)
        ->where(function ($q) {
            $q->whereNull('invoices.status')
              ->orWhere('invoices.status', '!=', 'cancelled');
        })
        ->sum('invoice_items.quantity');

    // 3. Khách trả hàng (không Đã hủy)
    if (DB::getSchemaBuilder()->hasTable('return_items')) {
        $stock += (int) DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('return_items.product_id', $product->id)
            ->where(function ($q) {
                $q->where('returns.status', '!=', 'Đã hủy')
                  ->orWhereNull('returns.status');
            })
            ->sum('return_items.quantity');
    }

    // 4. Trả hàng NCC (completed)
    if (DB::getSchemaBuilder()->hasTable('purchase_return_items')) {
        $stock -= (int) DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.product_id', $product->id)
            ->where('purchase_returns.status', 'completed')
            ->sum('purchase_return_items.quantity');
    }

    // 5. Kiểm kho (chỉ phiếu đã cân bằng)
    if (DB::getSchemaBuilder()->hasTable('stock_take_items')) {
        $rows = DB::table('stock_take_items')
            ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
            ->where('stock_take_items.product_id', $product->id)
            ->where('stock_takes.status', 'balanced')
            ->get(['stock_take_items.actual_stock', 'stock_take_items.system_stock']);
        foreach ($rows as $r) {
            $stock += ((int)$r->actual_stock - (int)$r->system_stock);
        }
    }

    // 6. Xuất hủy
    if (DB::getSchemaBuilder()->hasTable('damage_items')) {
        $stock -= (int) DB::table('damage_items')
            ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
            ->where('damage_items.product_id', $product->id)
            ->sum('damage_items.qty');
    }

    // 7. Task parts — linh kiện (export = -qty, import = +qty)
    if (DB::getSchemaBuilder()->hasTable('task_parts')) {
        $exports = (int) DB::table('task_parts')
            ->where('product_id', $product->id)
            ->where(function ($q) {
                $q->where('direction', 'export')->orWhereNull('direction');
            })
            ->sum('quantity');

        $imports = (int) DB::table('task_parts')
            ->where('product_id', $product->id)
            ->where('direction', 'import')
            ->sum('quantity');

        $stock = $stock - $exports + $imports;
    }

    // 8. Chuyển kho (trừ)
    if (DB::getSchemaBuilder()->hasTable('stock_transfer_items')) {
        $stock -= (int) DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfer_items.product_id', $product->id)
            ->where('stock_transfers.status', 'completed')
            ->sum('stock_transfer_items.quantity');
    }

    $oldStock = (int) $product->stock_quantity;
    
    if ($oldStock !== $stock) {
        $diff = $stock - $oldStock;
        $sign = $diff > 0 ? "+{$diff}" : "{$diff}";
        echo "  ❌ {$product->sku} | {$product->name}\n";
        echo "     CŨ: {$oldStock} → MỚI: {$stock} ({$sign})\n";
        
        if (!$dryRun) {
            DB::table('products')->where('id', $product->id)
                ->update(['stock_quantity' => $stock]);
        }
        $fixed++;
    } else {
        $same++;
    }
}

echo "\n═══════════════════════════════════════════════\n";
echo "📊 KẾT QUẢ\n";
echo "   Tổng SP: {$total}\n";
echo "   Đã đúng: {$same}\n";
echo "   Cần sửa: {$fixed}\n";
if ($dryRun) {
    echo "\n   ⚠️  DRY-RUN — chưa sửa gì. Chạy lại KHÔNG có --dry-run để cập nhật.\n";
} else {
    echo "\n   ✅ Đã cập nhật {$fixed} sản phẩm.\n";
}
echo "═══════════════════════════════════════════════\n";
