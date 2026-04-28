<?php
/**
 * SCRIPT CHẨN ĐOÁN THẺ KHO CHI TIẾT
 * 
 * Chạy: php diagnose_product.php <product_id hoặc SKU>
 * Ví dụ: php diagnose_product.php SP260316719
 * 
 * Script này hiển thị TỪNG giao dịch theo thứ tự thời gian,
 * với SL tồn chạy (running balance) để phát hiện chỗ sai.
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\TaskPart;
use App\Models\Task;

$search = $argv[1] ?? null;
if (!$search) {
    echo "Usage: php diagnose_product.php <product_id hoặc SKU>\n";
    exit(1);
}

$product = Product::where('id', $search)->orWhere('sku', $search)->first();
if (!$product) {
    echo "Không tìm thấy sản phẩm: {$search}\n";
    exit(1);
}

echo str_repeat('=', 100) . "\n";
echo "SẢN PHẨM: #{$product->id} {$product->sku} \"{$product->name}\"\n";
echo "DB hiện tại: stock_quantity={$product->stock_quantity}, cost_price=" . number_format($product->cost_price, 0) . ", total=" . number_format($product->inventory_total_cost, 0) . "\n";
echo "has_serial: " . ($product->has_serial ? 'CÓ' : 'KHÔNG') . "\n";
echo str_repeat('=', 100) . "\n\n";

$events = [];

// ═══════════════════════════════════════
// 1. NHẬP MUA
// ═══════════════════════════════════════
$purchaseItems = DB::table('purchase_items')
    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
    ->where('purchase_items.product_id', $product->id)
    ->where('purchases.status', 'completed')
    ->orderBy('purchases.created_at')
    ->get(['purchase_items.*', 'purchases.code as p_code', 'purchases.created_at as ts', 'purchases.status as p_status']);

foreach ($purchaseItems as $pi) {
    $unitCost = (float) ($pi->unit_cost_allocated ?? $pi->price ?? 0);
    $events[] = [
        'ts' => $pi->ts,
        'type' => 'NHẬP MUA',
        'code' => $pi->p_code,
        'qty_change' => +(int) $pi->quantity,
        'cost_change' => +(int) $pi->quantity * $unitCost,
        'detail' => "qty={$pi->quantity} × " . number_format($unitCost, 0) . " = " . number_format($pi->quantity * $unitCost, 0),
    ];
}

// ═══════════════════════════════════════
// 2. BÁN HÀNG
// ═══════════════════════════════════════
$invoiceItems = DB::table('invoice_items')
    ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
    ->where('invoice_items.product_id', $product->id)
    ->where(function ($q) {
        $q->whereNull('invoices.status')->orWhere('invoices.status', '!=', 'cancelled');
    })
    ->orderBy('invoices.created_at')
    ->get(['invoice_items.*', 'invoices.code as i_code', 'invoices.created_at as ts', 'invoices.status as i_status']);

foreach ($invoiceItems as $ii) {
    $events[] = [
        'ts' => $ii->ts,
        'type' => 'BÁN HÀNG',
        'code' => $ii->i_code,
        'qty_change' => -(int) $ii->quantity,
        'cost_change' => null, // tính BQ tại runtime
        'detail' => "qty={$ii->quantity}, status={$ii->i_status}",
    ];
}

// ═══════════════════════════════════════
// 3. KHÁCH TRẢ HÀNG
// ═══════════════════════════════════════
$returnItems = DB::table('return_items')
    ->join('returns', 'returns.id', '=', 'return_items.return_id')
    ->where('return_items.product_id', $product->id)
    ->where(function ($q) {
        $q->where('returns.status', '!=', 'Đã hủy')->orWhereNull('returns.status');
    })
    ->get(['return_items.*', 'returns.code as r_code', 'returns.created_at as ts', 'returns.status as r_status']);

foreach ($returnItems as $ri) {
    $events[] = [
        'ts' => $ri->ts,
        'type' => 'KH TRẢ HÀNG',
        'code' => $ri->r_code,
        'qty_change' => +(int) $ri->quantity,
        'cost_change' => (int) $ri->quantity * (float) ($ri->cost_price ?? 0),
        'detail' => "qty={$ri->quantity}, cost_price=" . number_format($ri->cost_price ?? 0, 0),
    ];
}

// ═══════════════════════════════════════
// 4. TRẢ HÀNG NCC
// ═══════════════════════════════════════
$prItems = DB::table('purchase_return_items')
    ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
    ->where('purchase_return_items.product_id', $product->id)
    ->where('purchase_returns.status', 'completed')
    ->get(['purchase_return_items.*', 'purchase_returns.code as pr_code', 'purchase_returns.created_at as ts']);

foreach ($prItems as $pri) {
    $unitCost = (float) ($pri->cost_price ?? $pri->price ?? 0);
    $events[] = [
        'ts' => $pri->ts,
        'type' => 'TRẢ NCC',
        'code' => $pri->pr_code,
        'qty_change' => -(int) $pri->quantity,
        'cost_change' => -(int) $pri->quantity * $unitCost,
        'detail' => "qty={$pri->quantity} × " . number_format($unitCost, 0),
    ];
}

// ═══════════════════════════════════════
// 5. KIỂM KHO
// ═══════════════════════════════════════
if (DB::getSchemaBuilder()->hasTable('stock_take_items')) {
    $stItems = DB::table('stock_take_items')
        ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
        ->where('stock_take_items.product_id', $product->id)
        ->get(['stock_take_items.*', 'stock_takes.code as st_code', 'stock_takes.status as st_status', 'stock_takes.created_at as ts']);

    foreach ($stItems as $sti) {
        $diff = (int) $sti->actual_stock - (int) $sti->system_stock;
        $events[] = [
            'ts' => $sti->ts,
            'type' => 'KIỂM KHO',
            'code' => $sti->st_code . " (status={$sti->st_status})",
            'qty_change' => $diff,
            'cost_change' => null,
            'detail' => "system={$sti->system_stock} actual={$sti->actual_stock} diff={$diff}",
        ];
    }
}

// ═══════════════════════════════════════
// 6. XUẤT HỦY
// ═══════════════════════════════════════
if (DB::getSchemaBuilder()->hasTable('damage_items')) {
    $dmgItems = DB::table('damage_items')
        ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
        ->where('damage_items.product_id', $product->id)
        ->get(['damage_items.*', 'damages.code as d_code', 'damages.status as d_status', 'damages.created_at as ts']);

    foreach ($dmgItems as $di) {
        $events[] = [
            'ts' => $di->ts,
            'type' => 'XUẤT HỦY',
            'code' => $di->d_code . " (status={$di->d_status})",
            'qty_change' => -(int) $di->qty,
            'cost_change' => null,
            'detail' => "qty={$di->qty}",
        ];
    }
}

// ═══════════════════════════════════════
// 7a. LINH KIỆN XUẤT/NHẬP (product_id = SP này)
// ═══════════════════════════════════════
if (DB::getSchemaBuilder()->hasTable('task_parts')) {
    $componentParts = TaskPart::where('product_id', $product->id)
        ->orderBy('created_at')->orderBy('id')->get();
    
    echo ">>> task_parts WHERE product_id={$product->id}: " . $componentParts->count() . " dòng\n";
    
    foreach ($componentParts as $p) {
        $isImport = ($p->direction ?? 'export') === 'import';
        $task = Task::find($p->task_id);
        $machineName = $task?->product?->name ?? $task?->title ?? '?';
        $events[] = [
            'ts' => $p->created_at,
            'type' => $isImport ? 'LK NHẬP (bóc máy)' : 'LK XUẤT (sửa chữa)',
            'code' => "task#{$p->task_id} → {$machineName}",
            'qty_change' => $isImport ? +(int) ($p->quantity ?? 1) : -(int) ($p->quantity ?? 1),
            'cost_change' => $isImport ? +(float) $p->total_cost : -(float) $p->total_cost,
            'detail' => "qty={$p->quantity} cost=" . number_format($p->total_cost, 0) . " dir={$p->direction}",
        ];
    }

    // 7b. MÁY ĐƯỢC SỬA (task.product_id = SP này)
    $taskIds = Task::where(function ($q) use ($product) {
            $q->where('product_id', $product->id);
            $q->orWhereHas('serialImei', fn($s) => $s->where('product_id', $product->id));
        })
        ->pluck('id')->toArray();
    
    if (!empty($taskIds)) {
        $machineParts = TaskPart::whereIn('task_id', $taskIds)
            ->where('product_id', '!=', $product->id)
            ->orderBy('created_at')->orderBy('id')->get();
        
        echo ">>> task_parts for MACHINE repair (task gắn SP này): " . $machineParts->count() . " dòng\n";
        
        foreach ($machineParts as $p) {
            $isImport = ($p->direction ?? 'export') === 'import';
            $partName = $p->product?->name ?? "product#{$p->product_id}";
            $events[] = [
                'ts' => $p->created_at,
                'type' => $isImport ? 'MÁY: THÁO LK' : 'MÁY: LẮP LK',
                'code' => "task#{$p->task_id} ← {$partName}",
                'qty_change' => 0, // S máy KHÔNG đổi
                'cost_change' => $isImport ? -(float) $p->total_cost : +(float) $p->total_cost,
                'detail' => "qty={$p->quantity} cost=" . number_format($p->total_cost, 0) . " (S giữ nguyên)",
            ];
        }
    }
}

// ═══════════════════════════════════════
// 8. STOCK_MOVEMENTS (chỉ để so sánh, KHÔNG dùng trong tính toán)
// ═══════════════════════════════════════
$smCount = DB::table('stock_movements')->where('product_id', $product->id)->count();
$ibCount = DB::table('stock_movements')->where('product_id', $product->id)->where('type', 'initial_balance')->count();
echo "\n>>> stock_movements: {$smCount} dòng (initial_balance: {$ibCount})\n";

if ($ibCount > 0) {
    $ibs = DB::table('stock_movements')
        ->where('product_id', $product->id)
        ->where('type', 'initial_balance')
        ->get(['qty', 'direction', 'unit_cost', 'ref_code', 'moved_at']);
    foreach ($ibs as $ib) {
        echo "    ⚠️ initial_balance: qty={$ib->qty} dir={$ib->direction} cost=" . number_format($ib->unit_cost, 0) . " ref={$ib->ref_code} date={$ib->moved_at}\n";
    }
    echo "    ⚠️ Initial balances TỒN TẠI — có thể gây lệch thẻ kho hiển thị!\n";
}

// ═══════════════════════════════════════
// SẮP XẾP VÀ HIỂN THỊ TIMELINE
// ═══════════════════════════════════════
usort($events, fn($a, $b) => strcmp($a['ts'] ?? '', $b['ts'] ?? ''));

echo "\n" . str_repeat('─', 140) . "\n";
printf("%-20s | %-20s | %-35s | %8s | %15s | %s\n", 'THỜI GIAN', 'LOẠI', 'MÃ PHIẾU', 'SL THAY', 'TỒN SAU', 'CHI TIẾT');
echo str_repeat('─', 140) . "\n";

$runningQty = 0;
$runningTotal = 0.0;
$totalPurchase = 0;
$totalSale = 0;
$totalReturn = 0;
$totalPR = 0;
$totalPartExport = 0;
$totalPartImport = 0;
$totalStockTake = 0;
$totalDamage = 0;

foreach ($events as $e) {
    $qtyChange = $e['qty_change'];
    $runningQty += $qtyChange;
    if ($runningQty < 0) $runningQty = 0;
    
    // Count
    if (str_contains($e['type'], 'NHẬP MUA')) $totalPurchase += abs($qtyChange);
    if (str_contains($e['type'], 'BÁN HÀNG')) $totalSale += abs($qtyChange);
    if (str_contains($e['type'], 'KH TRẢ')) $totalReturn += abs($qtyChange);
    if (str_contains($e['type'], 'TRẢ NCC')) $totalPR += abs($qtyChange);
    if (str_contains($e['type'], 'LK XUẤT')) $totalPartExport += abs($qtyChange);
    if (str_contains($e['type'], 'LK NHẬP')) $totalPartImport += abs($qtyChange);
    if (str_contains($e['type'], 'KIỂM KHO')) $totalStockTake += $qtyChange;
    if (str_contains($e['type'], 'XUẤT HỦY')) $totalDamage += abs($qtyChange);
    
    $sign = $qtyChange >= 0 ? '+' : '';
    printf("%-20s | %-20s | %-35s | %s%-7d | %15d | %s\n",
        substr($e['ts'] ?? '', 0, 19),
        $e['type'],
        mb_substr($e['code'], 0, 35),
        $sign, $qtyChange,
        $runningQty,
        $e['detail']
    );
}

echo str_repeat('─', 140) . "\n\n";

echo "═══════════════════ TỔNG KẾT ═══════════════════\n";
echo "  Nhập mua:        +{$totalPurchase}\n";
echo "  Bán hàng:        -{$totalSale}\n";
echo "  KH trả hàng:     +{$totalReturn}\n";
echo "  Trả NCC:         -{$totalPR}\n";
echo "  Kiểm kho:        {$totalStockTake}\n";
echo "  Xuất hủy:        -{$totalDamage}\n";
echo "  LK xuất SC:      -{$totalPartExport}\n";
echo "  LK nhập bóc máy: +{$totalPartImport}\n";
echo "  ────────────────────────────\n";
$expected = $totalPurchase - $totalSale + $totalReturn - $totalPR + $totalStockTake - $totalDamage - $totalPartExport + $totalPartImport;
echo "  TỔNG KỲ VỌNG:    {$expected}\n";
echo "  REBUILD TỒN:     {$runningQty} (theo timeline)\n";
echo "  DB HIỆN TẠI:     {$product->stock_quantity}\n";
echo "\n";

if ($expected !== $runningQty) {
    echo "  ⚠️ TỔNG KỲ VỌNG ≠ REBUILD: do clamp max(0,...) khi tồn âm\n";
}
if ($runningQty !== (int) $product->stock_quantity) {
    echo "  ❌ REBUILD ≠ DB: cần chạy lại rebuild\n";
}
echo str_repeat('=', 60) . "\n";
