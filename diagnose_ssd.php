<?php
/**
 * diagnose_ssd.php — Kiểm tra chi tiết giao dịch của SSD M2 256GB (SP260316719)
 * 
 * CHỈ ĐỌC DỮ LIỆU — KHÔNG SỬA GÌ
 * 
 * Chạy: php diagnose_ssd.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Tìm SSD M2 256GB Bóc Máy
$product = DB::table('products')->where('sku', 'SP260316719')->first();
if (!$product) {
    echo "❌ Không tìm thấy SP260316719\n";
    exit(1);
}

echo "═══════════════════════════════════════════════\n";
echo "📦 KIỂM TRA: {$product->name} (#{$product->id})\n";
echo "   SKU: {$product->sku}\n";
echo "   stock_quantity hiện tại trong DB: {$product->stock_quantity}\n";
echo "═══════════════════════════════════════════════\n\n";

// ── 1. NHẬP MUA ──
$purchases = DB::table('purchase_items')
    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
    ->where('purchase_items.product_id', $product->id)
    ->where('purchases.status', 'completed')
    ->get(['purchase_items.*', 'purchases.code', 'purchases.created_at as ts', 'purchases.status']);

$totalPurchaseQty = $purchases->sum('quantity');
echo "1. NHẬP MUA (purchase_items): {$purchases->count()} phiếu, tổng +{$totalPurchaseQty}\n";
foreach ($purchases as $p) {
    echo "   📥 {$p->code} | {$p->ts} | +{$p->quantity} x " . number_format($p->price) . "\n";
}
echo "\n";

// ── 2. BÁN HÀNG ──
$invoices = DB::table('invoice_items')
    ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
    ->where('invoice_items.product_id', $product->id)
    ->where(function ($q) {
        $q->whereNull('invoices.status')
          ->orWhere('invoices.status', '!=', 'cancelled');
    })
    ->get(['invoice_items.*', 'invoices.code', 'invoices.created_at as ts', 'invoices.status']);

$totalSaleQty = $invoices->sum('quantity');
echo "2. BÁN HÀNG (invoice_items): {$invoices->count()} hóa đơn, tổng -{$totalSaleQty}\n";
foreach ($invoices as $i) {
    $st = $i->status ?: 'active';
    echo "   📤 {$i->code} | {$i->ts} | -{$i->quantity} | status={$st}\n";
}
echo "\n";

// ── 3. XUẤT SỬA CHỮA (task_parts direction=export, product_id=SSD) ──
$exports = DB::table('task_parts')
    ->join('tasks', 'tasks.id', '=', 'task_parts.task_id')
    ->where('task_parts.product_id', $product->id)
    ->where(function ($q) {
        $q->where('task_parts.direction', 'export')
          ->orWhereNull('task_parts.direction'); // default = export
    })
    ->get(['task_parts.*', 'tasks.code as task_code', 'tasks.status as task_status', 'tasks.created_at as ts']);

$totalExportAll = $exports->sum('quantity');
$cancelledExports = $exports->where('task_status', 'cancelled');
$activeExports = $exports->where('task_status', '!=', 'cancelled');
$totalExportActive = $activeExports->sum('quantity');
$totalExportCancelled = $cancelledExports->sum('quantity');

echo "3. XUẤT SỬA CHỮA (task_parts direction=export): {$exports->count()} dòng\n";
echo "   ├── Task ACTIVE: {$activeExports->count()} dòng, tổng -{$totalExportActive}\n";
echo "   └── Task CANCELLED: {$cancelledExports->count()} dòng, tổng -{$totalExportCancelled}\n";
foreach ($exports as $e) {
    $flag = $e->task_status === 'cancelled' ? '🚫 HỦY' : '✅';
    echo "   {$flag} {$e->task_code} | {$e->ts} | -{$e->quantity} | task_status={$e->task_status}\n";
}
echo "\n";

// ── 4. NHẬP BÓC MÁY (task_parts direction=import, product_id=SSD) ──
$imports = DB::table('task_parts')
    ->join('tasks', 'tasks.id', '=', 'task_parts.task_id')
    ->where('task_parts.product_id', $product->id)
    ->where('task_parts.direction', 'import')
    ->get(['task_parts.*', 'tasks.code as task_code', 'tasks.status as task_status', 'tasks.created_at as ts']);

$totalImportAll = $imports->sum('quantity');
$cancelledImports = $imports->where('task_status', 'cancelled');
$activeImports = $imports->where('task_status', '!=', 'cancelled');
$totalImportActive = $activeImports->sum('quantity');
$totalImportCancelled = $cancelledImports->sum('quantity');

echo "4. NHẬP BÓC MÁY (task_parts direction=import): {$imports->count()} dòng\n";
echo "   ├── Task ACTIVE: {$activeImports->count()} dòng, tổng +{$totalImportActive}\n";
echo "   └── Task CANCELLED: {$cancelledImports->count()} dòng, tổng +{$totalImportCancelled}\n";
foreach ($imports as $i) {
    $flag = $i->task_status === 'cancelled' ? '🚫 HỦY' : '✅';
    echo "   {$flag} {$i->task_code} | {$i->ts} | +{$i->quantity} | task_status={$i->task_status}\n";
}
echo "\n";

// ── 5. CÁC BẢNG KHÁC ──
$returnQty = DB::table('return_items')
    ->join('returns', 'returns.id', '=', 'return_items.return_id')
    ->where('return_items.product_id', $product->id)
    ->where(function ($q) {
        $q->where('returns.status', '!=', 'Đã hủy')->orWhereNull('returns.status');
    })->sum('return_items.quantity');
echo "5. TRẢ HÀNG KHÁCH: +{$returnQty}\n";

$pReturnQty = 0;
if (DB::getSchemaBuilder()->hasTable('purchase_return_items')) {
    $pReturnQty = DB::table('purchase_return_items')
        ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
        ->where('purchase_return_items.product_id', $product->id)
        ->where('purchase_returns.status', 'completed')
        ->sum('purchase_return_items.quantity');
}
echo "6. TRẢ HÀNG NCC: -{$pReturnQty}\n";

$stDiff = 0;
if (DB::getSchemaBuilder()->hasTable('stock_take_items')) {
    $stDiff = DB::table('stock_take_items')
        ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
        ->where('stock_take_items.product_id', $product->id)
        ->where('stock_takes.status', 'balanced')
        ->selectRaw('SUM(actual_stock - system_stock) as diff')
        ->value('diff') ?? 0;
}
echo "7. KIỂM KHO (chênh lệch): {$stDiff}\n";

$dmgQty = 0;
if (DB::getSchemaBuilder()->hasTable('damage_items')) {
    $dmgQty = DB::table('damage_items')
        ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
        ->where('damage_items.product_id', $product->id)
        ->where('damages.status', 'completed')
        ->sum('damage_items.qty');
}
echo "8. XUẤT HỦY: -{$dmgQty}\n\n";

// ── TỔNG KẾT ──
echo "═══════════════════════════════════════════════\n";
echo "📊 TỔNG KẾT\n";
echo "═══════════════════════════════════════════════\n";

$calcAll = $totalPurchaseQty - $totalSaleQty - $totalExportAll + $totalImportAll + $returnQty - $pReturnQty + $stDiff - $dmgQty;
$calcActive = $totalPurchaseQty - $totalSaleQty - $totalExportActive + $totalImportActive + $returnQty - $pReturnQty + $stDiff - $dmgQty;

echo "\n";
echo "A) Đếm TẤT CẢ task_parts (kể cả task cancelled):\n";
echo "   +{$totalPurchaseQty} nhập - {$totalSaleQty} bán - {$totalExportAll} xuất SC + {$totalImportAll} bóc máy + {$returnQty} trả KH - {$pReturnQty} trả NCC + {$stDiff} kiểm kho - {$dmgQty} hủy = {$calcAll}\n";
echo "\n";
echo "B) Chỉ đếm task_parts của task CHƯA HỦY:\n";
echo "   +{$totalPurchaseQty} nhập - {$totalSaleQty} bán - {$totalExportActive} xuất SC + {$totalImportActive} bóc máy + {$returnQty} trả KH - {$pReturnQty} trả NCC + {$stDiff} kiểm kho - {$dmgQty} hủy = {$calcActive}\n";
echo "\n";
echo "C) stock_quantity trong DB hiện tại: {$product->stock_quantity}\n";
echo "D) Số thực tế kho (theo bạn): ~11\n";
echo "\n";

$diff = $calcAll - $calcActive;
echo "→ Chênh lệch do task cancelled: {$diff} cái\n";
echo "→ Chênh lệch A vs thực tế: " . ($calcAll - 11) . " cái\n";
echo "→ Chênh lệch B vs thực tế: " . ($calcActive - 11) . " cái\n";
echo "\n";
echo "═══════════════════════════════════════════════\n";
