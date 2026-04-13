<?php
/**
 * REPAIR SCRIPT: Khôi phục tồn kho sản phẩm serial/IMEI
 * 
 * Tính lại stock_quantity cho sản phẩm serial dựa trên:
 *   stock = serial(in_stock) count
 * 
 * Đồng thời đối chiếu với thẻ kho (purchase - invoice - return - damage)
 * 
 * Chạy:     php fix_serial_stock.php          (chỉ kiểm tra)
 * Sửa:      php fix_serial_stock.php --fix    (sửa stock)
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fix = in_array('--fix', $argv ?? []);

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  FIX: Serial Product Stock Recovery              ║\n";
echo "║  Mode: " . ($fix ? "🔧 FIX (will update DB)" : "👁️  DRY RUN (read only)") . "              ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

$serialProducts = \App\Models\Product::where('has_serial', true)->get();
$fixedCount = 0;
$alreadyCorrect = 0;

foreach ($serialProducts as $p) {
    // Count serials by status
    $inStockSerials = \App\Models\SerialImei::where('product_id', $p->id)
        ->where('status', 'in_stock')
        ->count();
    $soldSerials = \App\Models\SerialImei::where('product_id', $p->id)
        ->where('status', 'sold')
        ->count();
    $otherSerials = \App\Models\SerialImei::where('product_id', $p->id)
        ->whereNotIn('status', ['in_stock', 'sold'])
        ->count();
    $totalSerials = $inStockSerials + $soldSerials + $otherSerials;

    // Cross-check with transaction history
    $purchasedQty = \App\Models\PurchaseItem::whereHas('purchase', function ($q) use ($p) {
        $q->where('status', 'completed');
    })->where('product_id', $p->id)->sum('quantity');

    $soldQty = \App\Models\InvoiceItem::where('product_id', $p->id)->sum('quantity');

    $returnedToCustomer = \DB::table('return_items')
        ->where('product_id', $p->id)->sum('quantity');

    $returnedToSupplier = \App\Models\PurchaseReturnItem::whereHas('purchaseReturn', function ($q) {
        $q->where('status', 'completed');
    })->where('product_id', $p->id)->sum('quantity');

    $expectedStock = $purchasedQty - $soldQty + $returnedToCustomer - $returnedToSupplier;

    $dbStock = $p->stock_quantity;

    if ($dbStock == $inStockSerials && $dbStock == $expectedStock) {
        $alreadyCorrect++;
        continue;
    }

    echo "═══════════════════════════════════════════\n";
    echo "  {$p->sku} | {$p->name}\n";
    echo "  ─────────────────────────────────────────\n";
    echo "  DB stock_quantity:    {$dbStock}\n";
    echo "  Serial in_stock:     {$inStockSerials}\n";
    echo "  Serial sold:         {$soldSerials}\n";
    echo "  Serial other:        {$otherSerials} (returned/warranty/defective)\n";
    echo "  Serial total:        {$totalSerials}\n";
    echo "  ─── Thẻ kho ──────────────────────────\n";
    echo "  Đã nhập (purchase):  {$purchasedQty}\n";
    echo "  Đã bán (invoice):    {$soldQty}\n";
    echo "  KH trả hàng:        {$returnedToCustomer}\n";
    echo "  Trả NCC:            {$returnedToSupplier}\n";
    echo "  Expected stock:      {$expectedStock}\n";

    // Determine correct stock: prefer serial count (most reliable for serial products)
    $correctStock = $inStockSerials;

    if ($inStockSerials != $expectedStock) {
        echo "  ⚠️  CẢNH BÁO: Serial count ({$inStockSerials}) ≠ Thẻ kho ({$expectedStock})\n";
        echo "     → Sử dụng serial count (nguồn tin cậy hơn cho SP serial)\n";
    }

    if ($dbStock != $correctStock) {
        echo "  🔴 Cần sửa: {$dbStock} → {$correctStock}\n";
        if ($fix) {
            $p->update(['stock_quantity' => $correctStock]);
            echo "  ✅ ĐÃ SỬA stock_quantity = {$correctStock}\n";
        }
        $fixedCount++;
    }
    echo "\n";
}

echo "═══════════════════════════════════════════\n";
echo "  TỔNG KẾT\n";
echo "  Tổng SP serial:     " . $serialProducts->count() . "\n";
echo "  Đã đúng:            {$alreadyCorrect}\n";
echo "  Cần sửa/Đã sửa:    {$fixedCount}\n";
echo "═══════════════════════════════════════════\n";

if (!$fix && $fixedCount > 0) {
    echo "\n💡 Chạy lại với --fix để sửa: php fix_serial_stock.php --fix\n";
}
if ($fix) {
    echo "\n✅ Hoàn tất!\n";
}
